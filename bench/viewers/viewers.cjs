// Bedrock protocol viewers for benchmarks. Commands arrive as JSON lines on stdin, replies leave as JSON lines.
//   {"op":"join","names":["V1"]}   {"op":"leave","names":["V1"]}   {"op":"stats"}   {"op":"exit"}
const readline = require('node:readline')
const path = require('node:path')
const { openSession } = require(path.join(__dirname, '../../tests/bedrock/session.cjs'))

const port = Number(process.env.NPC_BENCH_PORT || 29184)
const sessions = new Map()
const errors = []

function bucketOf(session, second) {
  return session.buckets.get(second) ?? session.buckets.set(second, new Map()).get(second)
}

function count(session, name, bytes) {
  const cell = bucketOf(session, Math.floor(Date.now() / 1000))
  const entry = cell.get(name) ?? { count: 0, bytes: 0 }
  entry.count += 1
  entry.bytes += bytes
  cell.set(name, entry)
}

function join(name) {
  if (sessions.get(name)?.connected) return
  // a reconnecting viewer keeps its counters so traffic of every session counts
  const previous = sessions.get(name)
  const session = { name, connected: false, spawned: false, visible: new Set(), buckets: previous?.buckets ?? new Map(), joins: (previous?.joins ?? 0) + 1, closedAt: null }
  const client = openSession(name, { port })
  session.client = client
  sessions.set(name, session)
  client.on('spawn', () => { session.spawned = true; session.connected = true })
  client.on('close', () => { session.connected = false; session.closedAt = Date.now() })
  client.on('error', error => errors.push({ name, error: String(error?.message ?? error) }))
  client.on('kick', packet => errors.push({ name, kick: JSON.stringify(packet).slice(0, 200) }))
  client.on('packet', des => {
    const packetName = des.data.name
    count(session, packetName, des.fullBuffer?.length ?? des.buffer?.length ?? 0)
    const params = des.data.params
    if (packetName === 'add_player' || packetName === 'add_entity') session.visible.add(String(params.runtime_id ?? params.runtime_entity_id))
    else if (packetName === 'remove_entity') session.visible.delete(String(params.entity_id_self ?? params.runtime_id ?? params.runtime_entity_id))
  })
}

function leave(name) {
  const session = sessions.get(name)
  if (!session) return
  session.client.close()
  session.visible.clear()
}

function stats() {
  const out = {}
  for (const [name, session] of sessions) {
    const buckets = {}
    for (const [second, cell] of session.buckets) buckets[second] = Object.fromEntries(cell)
    out[name] = { connected: session.connected, spawned: session.spawned, joins: session.joins, visible: session.visible.size, buckets }
  }
  return { stats: out, errors, now: Date.now() / 1000 }
}

const commands = {
  join: ({ names }) => names.forEach((name, index) => setTimeout(() => join(name), index * 250)),
  leave: ({ names }) => names.forEach(leave),
  stats: () => console.log(JSON.stringify(stats())),
  exit: () => { for (const session of sessions.values()) session.client.close(); setTimeout(() => process.exit(0), 300) }
}

readline.createInterface({ input: process.stdin }).on('line', line => {
  try {
    const message = JSON.parse(line)
    const handler = commands[message.op]
    if (!handler) throw Error('unknown op ' + message.op)
    handler(message)
  } catch (error) {
    errors.push({ error: String(error?.message ?? error) })
  }
})
