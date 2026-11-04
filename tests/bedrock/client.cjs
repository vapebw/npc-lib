const fs = require('node:fs')
const path = require('node:path')
const readline = require('node:readline')
const crypto = require('node:crypto')
const { openSession, VERSION } = require('./session.cjs')
const players = new Map()
const outputDir = path.resolve(__dirname, '../../work/wire/client')
fs.mkdirSync(outputDir, { recursive: true })
const logfile = path.join(outputDir, 'wire-events.jsonl')
function log(event, data = {}) {
  const line = JSON.stringify({ at: new Date().toISOString(), event, ...data }, (_, v) => {
    if (typeof v === 'bigint') return v.toString()
    if (v?.type === 'Buffer' && Array.isArray(v.data)) return { bytes: v.data.length, sha256: crypto.createHash('sha256').update(Buffer.from(v.data)).digest('hex') }
    if (typeof v === 'string' && v.length > 4096) return { characters: v.length, sha256: crypto.createHash('sha256').update(v).digest('hex') }
    return v
  })
  fs.appendFileSync(logfile, line + '\n')
  console.log(line)
}
function transaction(action, target, position) {
  return { transaction: { legacy: { legacy_request_id: 0 }, transaction_type: 'item_use_on_entity', actions: [], transaction_data: {
    entity_runtime_id: BigInt(target), action_type: action, hotbar_slot: 0,
    held_item: { network_id: 0, count: 0, metadata: 0, has_stack_id: false, block_runtime_id: 0, extra: { has_nbt: false, can_place_on: [], can_destroy: [] } },
    player_pos: position, click_pos: { x: 0, y: 1, z: 0 }
  } } }
}
function commandPacket(command) {
  return { command: command.startsWith('/') ? command : '/' + command, origin: { type: 'player', uuid: crypto.randomUUID(), request_id: '', player_entity_id: 0n }, internal: false, version: '52' }
}
function connect(name) {
  if (players.get(name)?.client && !players.get(name).client._closed) throw Error('player is already connected')
  const state = { name, entities: new Map(), position: null, chunks: 0 }
  const client = openSession(name, { port: Number(process.env.NPC_TEST_PORT || 29184), profilesFolder: path.join(outputDir, 'auth-cache') })
  state.client = client
  players.set(name, state)
  for (const event of ['loggingIn', 'join', 'spawn', 'close']) client.on(event, () => log(event, { player: name, chunks: state.chunks }))
  client.on('kick', packet => log('kick', { player: name, packet }))
  client.on('error', error => log('error', { player: name, error: error.stack }))
  client.on('start_game', packet => { state.position = packet.player_position; state.runtimeId = packet.runtime_entity_id; log('start_game', { player: name, runtimeId: state.runtimeId, position: state.position }) })
  client.on('move_player', packet => { if (String(packet.runtime_id) === String(state.runtimeId)) state.position = packet.position; log('move_player', { player: name, packet }) })
  client.on('level_chunk', packet => { state.chunks++; log('level_chunk', { player: name, x: packet.x, z: packet.z, chunks: state.chunks }) })
  for (const type of ['add_player', 'add_entity']) client.on(type, packet => {
    const id = packet.runtime_id ?? packet.runtime_entity_id
    state.entities.set(String(id), packet)
    log(type, { player: name, packet })
  })
  for (const type of ['remove_entity', 'player_list', 'player_skin', 'text', 'command_output', 'set_entity_data', 'update_attributes', 'mob_equipment', 'mob_armor_equipment', 'entity_event', 'move_entity', 'move_entity_delta']) client.on(type, packet => log(type, { player: name, packet }))
  return state
}
function action(input) {
  if (input.action === 'connect' || input.action === 'reconnect') return connect(input.player)
  const state = players.get(input.player)
  if (!state) throw Error('unknown player')
  const client = state.client
  switch (input.action) {
    case 'close': client.close(); break
    case 'command': client.write('command_request', commandPacket(input.command)); break
    case 'attack': case 'interact': {
      const target = input.target ?? [...state.entities].find(([, p]) => p.username === input.targetName)?.[0]
      if (target == null || !state.position) throw Error('target or current player position unavailable')
      client.write('inventory_transaction', transaction(input.action, target, state.position)); break
    }
    case 'packet': client.write(input.name, input.params); break
    case 'state': log('state', { player: input.player, runtimeId: state.runtimeId, position: state.position, chunks: state.chunks, entities: [...state.entities] }); break
    default: throw Error('unknown action')
  }
  log('action_sent', { input })
}
if (process.argv.includes('--verify')) {
  const { createSerializer } = require('bedrock-protocol/src/transforms/serializer')
  const serializer = createSerializer(VERSION)
  for (const name of ['attack', 'interact']) log('serializer_verified', { name, bytes: serializer.createPacketBuffer({ name: 'inventory_transaction', params: transaction(name, '1', { x: 0, y: 64, z: 0 }) }).length })
  log('serializer_verified', { name: 'command_request', bytes: serializer.createPacketBuffer({ name: 'command_request', params: commandPacket('npctest') }).length })
} else {
  const names = (process.env.NPC_TEST_PLAYERS || 'NpcProbeA,NpcProbeB').split(',')
  for (const name of names) connect(name)
  readline.createInterface({ input: process.stdin }).on('line', line => { try { action(JSON.parse(line)) } catch (error) { log('action_error', { line, error: error.stack }) } })
  const stop = () => { for (const state of players.values()) state.client.close(); setTimeout(() => process.exit(), 250) }
  process.on('SIGINT', stop)
  process.on('SIGTERM', stop)
  if (process.env.NPC_TEST_SECONDS) setTimeout(stop, Number(process.env.NPC_TEST_SECONDS) * 1000)
}
