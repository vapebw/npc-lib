const { spawn } = require('node:child_process')
const fs = require('node:fs')
const path = require('node:path')
const readline = require('node:readline')
const { EventEmitter } = require('node:events')
const root = path.resolve(__dirname, '../..')
const stateFile = path.join(root, 'work/wire/server/data/plugins/NpcLibHarness/wire-state.json')
const serverConsole = path.join(root, 'work/wire/logs/console.log')
const outputDir = path.join(root, 'work/wire/client')
const evidence = path.join(outputDir, 'suite-evidence', new Date().toISOString().replace(/[:.]/g, '-'))
fs.mkdirSync(evidence, { recursive: true })
const wire = []
const received = new EventEmitter()
const results = []
let finished = false
const child = spawn(process.execPath, [path.join(__dirname, 'client.cjs')], { cwd: __dirname, stdio: ['pipe', 'pipe', 'pipe'] })
const output = fs.createWriteStream(path.join(evidence, 'client-output.jsonl'))
readline.createInterface({ input: child.stdout }).on('line', line => { output.write(line + '\n'); try { wire.push(JSON.parse(line)); received.emit('packet') } catch {} })
child.stderr.on('data', data => output.write(data))
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms))
async function waitFor(label, predicate, timeout = 20000) {
  if (predicate()) return
  await new Promise((resolve, reject) => {
    const inspect = () => { if (predicate()) { clearTimeout(timer); received.off('packet', inspect); resolve() } }
    const timer = setTimeout(() => { received.off('packet', inspect); reject(Error(label + ' deadline exceeded')) }, timeout)
    received.on('packet', inspect)
  })
}
const send = action => child.stdin.write(JSON.stringify(action) + '\n')
const command = async command => { send({ player: 'NpcProbeA', action: 'command', command: 'npctest ' + command }); await sleep(350) }
const state = () => JSON.parse(fs.readFileSync(stateFile, 'utf8'))
const snapshot = async label => { await command('state'); const data = state(); fs.writeFileSync(path.join(evidence, label + '.json'), JSON.stringify(data, null, 2)); return data }
function check(label, ok, detail = {}) { const result = { label, result: ok ? 'PASS' : 'FAIL', ...detail }; results.push(result); console.log(JSON.stringify(result)); fs.writeFileSync(path.join(evidence, 'results.json'), JSON.stringify({ results, evidence }, null, 2)) }
const active = s => s.npcs.filter(n => !n.closed)
const last = (s, type, id) => s.events.filter(e => e.type === type && e.runtimeId === id).at(-1)
const hit = async (player, target, action = 'attack') => { await sleep(1200); send({ player, action, target: String(target) }); await sleep(400) }
const since = (offset, event, player, id) => wire.slice(offset).filter(e => e.event === event && e.player === player && (id == null || String(e.packet?.runtime_id ?? e.packet?.runtime_entity_id ?? e.packet?.entity_id_self) === String(id)))
async function scenario(label, body) { try { await body() } catch (error) { check(label, false, { error: error.stack }) } }
async function main() {
  const spawned = () => ['NpcProbeA', 'NpcProbeB'].every(p => wire.some(e => e.player === p && e.event === 'spawn') && wire.some(e => e.player === p && e.event === 'level_chunk'))
  await waitFor('both clients spawn and receive chunks', spawned).catch(() => {})
  check('two-real-spawns', spawned())
  await command('create NpcProbeA')
  let s = await snapshot('01-created'); let [p, v] = active(s)
  if (!p || !v) throw Error('two NPCs unavailable')
  await scenario('spawn-close-reentrant', async () => {
    const offset = wire.length
    const consoleOffset = fs.statSync(serverConsole).size
    await command('spawn-close'); await sleep(700)
    s = await snapshot('01a-spawn-close')
    const probe = s.spawnCloseProbe
    const consoleDelta = fs.readFileSync(serverConsole).subarray(consoleOffset).toString('utf8')
    fs.writeFileSync(path.join(evidence, 'spawn-close-server-console.log'), consoleDelta)
    const arrivals = ['NpcProbeA', 'NpcProbeB'].map(player => ({ player, adds: since(offset, 'add_player', player, probe.id).length, removes: since(offset, 'remove_entity', player, probe.id).length }))
    check('spawn-close-reentrant', s.error === null && !/ERROR|CRITICAL|Uncaught|Unhandled/i.test(consoleDelta) && probe.triggered === true && probe.expectedAbort === true && probe.count === 0 && probe.entityClosed === true && probe.weakReleased === true && s.count === 2 && arrivals.every(a => a.adds <= 1 && a.removes <= 1 && a.adds === a.removes) && arrivals.filter(a => a.adds === 1).length === 1, { probe, arrivals, consoleDelta })
  })
  await scenario('player-skin', async () => { const offset = wire.length; await command('skin'); await waitFor('skin received by both viewers', () => ['NpcProbeA', 'NpcProbeB'].every(player => since(offset, 'player_skin', player).some(e => e.packet.skin.skin_id === 'wire-npc-updated'))); await snapshot('01b-skin-updated'); check('player-skin', ['NpcProbeA', 'NpcProbeB'].every(player => since(offset, 'player_skin', player).length === 1 && since(offset, 'player_skin', player)[0].packet.skin.skin_id === 'wire-npc-updated')) })
  await scenario('protected', async () => { await command('flags protected'); await hit('NpcProbeA', p.id); await hit('NpcProbeB', v.id, 'interact'); s = await snapshot('02-protected'); check('protected', active(s).every(n => n.health === 20) && last(s, 'native-damage', p.id)?.cancelled === true && last(s, 'native-interact', v.id)?.cancelled === true) })
  await scenario('open-damage', async () => { await command('flags open'); await hit('NpcProbeA', p.id); await hit('NpcProbeB', v.id); s = await snapshot('03-open-damage'); check('open-damage', active(s).every(n => n.health < 20) && last(s, 'native-damage', p.id)?.cancelled === false && last(s, 'native-damage', v.id)?.player === 'NpcProbeB', { health: active(s).map(n => n.health) }) })
  await scenario('cancel-damage-uncancel', async () => { const before = active(s).find(n => n.id === p.id).health; await command('flags cancel-damage'); await command('uncancel on'); await hit('NpcProbeA', p.id); s = await snapshot('04-cancel-damage'); check('cancel-damage-uncancel', s.npcs.find(n => n.id === p.id).health === before && last(s, 'native-damage', p.id)?.cancelled === true) })
  await scenario('prior-cancellation', async () => { await command('precancel on'); await command('flags open'); const before = s.events.length; const health = s.npcs.find(n => n.id === p.id).health; await hit('NpcProbeA', p.id); s = await snapshot('05-prior-cancellation'); const fresh = s.events.slice(before); check('prior-cancellation', fresh.some(e => e.type === 'native-damage' && e.runtimeId === p.id && e.cancelled === true) && !fresh.some(e => e.type.startsWith('callback-')) && s.npcs.find(n => n.id === p.id).health === health); await command('precancel off'); await command('uncancel off') })
  await scenario('interact-flags', async () => { await command('flags allow-interact'); await hit('NpcProbeB', v.id, 'interact'); s = await snapshot('06-interact-allow'); check('interact-allow', last(s, 'native-interact', v.id)?.cancelled === false && last(s, 'native-interact', v.id)?.player === 'NpcProbeB'); await command('flags cancel-interact'); await hit('NpcProbeB', v.id, 'interact'); s = await snapshot('07-interact-cancel'); check('interact-cancel', last(s, 'native-interact', v.id)?.cancelled === true) })
  await scenario('viewer-chunks', async () => {
    let offset = wire.length
    await command('far NpcProbeB'); await sleep(1800)
    s = await snapshot('08-far-B')
    check('viewer-far-B', active(s).every(n => n.viewers.length === 1 && n.viewers[0] === 'NpcProbeA') && [p.id, v.id].every(id => since(offset, 'remove_entity', 'NpcProbeB', id).length === 1))
    const before = s.events.length
    const health = s.npcs.find(n => n.id === p.id).health
    await hit('NpcProbeB', p.id)
    s = await snapshot('08b-native-invalid-reach')
    const fresh = s.events.slice(before)
    check('native-invalid-reach', fresh.some(e => e.type === 'native-damage' && e.runtimeId === p.id && e.player === 'NpcProbeB' && e.cancelled === true) && !fresh.some(e => e.type.startsWith('callback-')) && s.npcs.find(n => n.id === p.id).health === health)
    offset = wire.length
    await command('near NpcProbeB'); await sleep(1800)
    s = await snapshot('09-near-B')
    check('viewer-near-B', active(s).every(n => n.viewers.length === 2) && since(offset, 'add_player', 'NpcProbeB', p.id).length === 1 && since(offset, 'add_entity', 'NpcProbeB', v.id).length === 1)
  })
  await scenario('reconnect', async () => { const oldId = wire.filter(e => e.player === 'NpcProbeB' && e.event === 'start_game').at(-1)?.runtimeId; send({ player: 'NpcProbeB', action: 'close' }); await sleep(700); const offset = wire.length; send({ player: 'NpcProbeB', action: 'reconnect' }); await sleep(3500); s = await snapshot('10-reconnect'); const newId = wire.filter(e => e.player === 'NpcProbeB' && e.event === 'start_game').at(-1)?.runtimeId; check('reconnect', oldId !== newId && active(s).every(n => n.viewers.length === 2 && n.viewers.filter(p => p === 'NpcProbeB').length === 1) && since(offset, 'add_player', 'NpcProbeB', p.id).length === 1 && since(offset, 'add_entity', 'NpcProbeB', v.id).length === 1, { oldId, newId }) })
  await scenario('npc-chunk-move', async () => { const offset = wire.length; await command('move-npc far'); await sleep(1800); s = await snapshot('11-npcs-far'); check('npc-move-far', active(s).every(n => n.viewers.length === 0) && ['NpcProbeA', 'NpcProbeB'].every(name => [p.id, v.id].every(id => since(offset, 'remove_entity', name, id).length >= 1))); const near = wire.length; await command('move-npc near'); await sleep(1800); s = await snapshot('12-npcs-near'); check('npc-move-near', active(s).every(n => n.viewers.length === 2) && ['NpcProbeA', 'NpcProbeB'].every(name => since(near, 'add_player', name, p.id).length === 1 && since(near, 'add_entity', name, v.id).length === 1)) })
  await scenario('close-on-attack', async () => { await command('flags open'); await command('close-on-attack on'); const before = s.events.filter(e => e.type === 'callback-damage' && e.runtimeId === p.id).length; const offset = wire.length; await hit('NpcProbeA', p.id); await sleep(500); s = await snapshot('13-close-attack'); check('close-on-attack', s.npcs.find(n => n.id === p.id).closed && s.count === 1 && s.npcs.find(n => n.id === p.id).weakReleased && s.events.filter(e => e.type === 'callback-damage' && e.runtimeId === p.id).length === before && ['NpcProbeA', 'NpcProbeB'].every(name => since(offset, 'remove_entity', name, p.id).length === 1)); await command('close-on-attack off') })
  await scenario('lethal-close', async () => { const oldIds = [p.id, v.id]; await command('create NpcProbeA'); await command('close-on-death on'); await command('lethal'); s = await snapshot('14-lethal-ready'); [p, v] = active(s); await hit('NpcProbeA', p.id); await sleep(700); s = await snapshot('15-lethal-closed'); check('lethal-close', !oldIds.includes(p.id) && s.npcs.find(n => n.id === p.id).closed && last(s, 'callback-death', p.id)?.player === 'NpcProbeA' && last(s, 'native-death', p.id)?.player === 'NpcProbeA'); await command('close-on-death off') })
  await scenario('remove-and-no-respawn', async () => { await command('remove'); await sleep(700); s = await snapshot('16-removed'); check('remove', s.count === 0 && s.npcs.every(n => n.closed && n.weakReleased)); send({ player: 'NpcProbeB', action: 'close' }); await sleep(700); const offset = wire.length; send({ player: 'NpcProbeB', action: 'reconnect' }); await sleep(3500); s = await snapshot('17-removed-reconnect'); check('no-respawn-after-remove', s.count === 0 && !wire.slice(offset).some(e => ['add_player', 'add_entity'].includes(e.event) && e.player === 'NpcProbeB' && s.npcs.some(n => String(n.id) === String(e.packet?.runtime_id)))) })
  await command('create NpcProbeA'); await snapshot('18-live-for-owner-cleanup')
  const observers = ['NpcProbeA', 'NpcProbeB']
  const meta = (offset, observer, id) => since(offset, 'set_entity_data', observer, id).flatMap(e => e.packet.metadata)
  const metaValue = (list, key) => list.filter(m => m.key === key).at(-1)?.value
  const flag = (list, name) => metaValue(list, 'flags')?.[name] === true
  const armorOf = (offset, observer, id) => { const p = since(offset, 'mob_armor_equipment', observer, id).at(-1)?.packet; return p && [p.helmet, p.chestplate, p.leggings, p.boots].map(i => i.network_id) }
  const handOf = (offset, observer, id, window) => since(offset, 'mob_equipment', observer, id).filter(e => e.packet.window_id === window).at(-1)?.packet.item.network_id
  const close = (a, b, tol = 1.5) => Math.abs((((a - b) % 360) + 540) % 360 - 180) <= tol
  const byName = (state, name) => state.npcs.find(n => n.name === name && !n.closed)
  const kit = { ids: {}, expected: {} }
  await scenario('kit-spawn-equipment', async () => {
    const offset = wire.length
    await command('kit-create NpcProbeA'); await sleep(1800)
    s = await snapshot('19-kit-created')
    const pl = byName(s, 'KitPlayer'), zb = byName(s, 'KitZombie'), vl = byName(s, 'KitVillager')
    kit.ids = { pl: pl.id, zb: zb.id, vl: vl.id }
    const results = observers.map(o => {
      const added = since(offset, 'add_player', o, pl.id)[0]?.packet
      const armor = armorOf(offset, o, pl.id), zarmor = armorOf(offset, o, zb.id)
      return {
        o, held: added?.held_item?.network_id, armor, offhand: handOf(offset, o, pl.id, 'offhand'), zarmor, varmor: armorOf(offset, o, vl.id),
        adds: since(offset, 'add_player', o, pl.id).length + since(offset, 'add_entity', o, zb.id).length + since(offset, 'add_entity', o, vl.id).length,
        armorPackets: since(offset, 'mob_armor_equipment', o, pl.id).length + since(offset, 'mob_armor_equipment', o, zb.id).length
      }
    })
    const a = results[0]
    kit.expected = { held: a.held, helmet: a.armor?.[0], chestplate: a.armor?.[1], boots: a.armor?.[3], offhand: a.offhand, zhelmet: a.zarmor?.[0], zlegs: a.zarmor?.[2] }
    check('kit-spawn-equipment', results.every(r => r.held > 0 && r.armor?.[0] > 0 && r.armor[1] > 0 && r.armor[2] === 0 && r.armor[3] > 0 && r.offhand > 0 && r.zarmor?.[0] > 0 && r.zarmor[1] === 0 && r.zarmor[2] > 0 && r.zarmor[3] === 0 && r.varmor?.every(i => i === 0) && r.adds === 3 && r.armorPackets === 2) && results.every(r => r.held === a.held && r.armor.join() === a.armor.join() && r.zarmor.join() === a.zarmor.join()), { results })
  })
  await scenario('kit-equipment-update', async () => {
    const offset = wire.length
    await command('kit equip'); await sleep(1200)
    const results = observers.map(o => ({
      o, armor: armorOf(offset, o, kit.ids.pl), main: handOf(offset, o, kit.ids.pl, 'inventory'), offhand: handOf(offset, o, kit.ids.pl, 'offhand'), zarmor: armorOf(offset, o, kit.ids.zb),
      counts: [since(offset, 'mob_armor_equipment', o, kit.ids.pl).length, since(offset, 'mob_equipment', o, kit.ids.pl).length, since(offset, 'mob_armor_equipment', o, kit.ids.zb).length, since(offset, 'mob_armor_equipment', o, kit.ids.vl).length]
    }))
    const e = kit.expected
    kit.expected.newHelmet = results[0].armor?.[0]
    kit.expected.newMain = results[0].main
    kit.expected.newChest = results[0].zarmor?.[1]
    check('kit-equipment-update', results.every(r => r.armor?.[0] > 0 && r.armor[0] !== e.helmet && r.armor[1] === e.chestplate && r.armor[3] === e.boots && r.main === e.offhand && r.offhand === 0 && r.zarmor?.[0] === e.zhelmet && r.zarmor[1] > 0 && r.zarmor[2] === e.zlegs && r.counts.join() === '1,2,1,0'), { results })
  })
  await scenario('kit-appearance', async () => {
    const offset = wire.length
    await command('kit appearance'); await sleep(1200)
    const results = observers.map(o => {
      const [pm, zm, vm] = [kit.ids.pl, kit.ids.zb, kit.ids.vl].map(id => meta(offset, o, id))
      return { o, scales: [pm, zm, vm].map(m => metaValue(m, 'scale')), sneaking: flag(pm, 'sneaking'), invisible: flag(zm, 'invisible'), tags: [pm, zm, vm].map(m => metaValue(m, 'score_tag')), packets: [kit.ids.pl, kit.ids.zb, kit.ids.vl].map(id => since(offset, 'set_entity_data', o, id).length), playerInvisible: flag(pm, 'invisible') }
    })
    check('kit-appearance', results.every(r => r.scales.every(v => v === 2) && r.sneaking && r.invisible && !r.playerInvisible && r.tags.join() === [kit.ids.pl, kit.ids.zb, kit.ids.vl].map(id => 'score-' + id).join() && r.packets.every(c => c === 1)), { results })
  })
  await scenario('kit-rotation', async () => {
    const offset = wire.length
    await command('kit rotate'); await sleep(1200)
    const results = observers.map(o => {
      const rot = id => since(offset, 'move_entity', o, id)
      const [pr, zr, vr] = [kit.ids.pl, kit.ids.zb, kit.ids.vl].map(id => rot(id).at(-1)?.packet.rotation)
      return { o, player: pr, zombie: zr, villager: vr, counts: [kit.ids.pl, kit.ids.zb, kit.ids.vl].map(id => rot(id).length) }
    })
    // bedrock-protocol labels pitch and yaw swapped on move_entity
    check('kit-rotation', results.every(r => r.counts.every(c => c === 1) && close(r.player.yaw, 30) && close(r.player.pitch, 90) && close(r.player.head_yaw, 90) && close(r.zombie.pitch, 270) && close(r.zombie.head_yaw, 270) && r.zombie.yaw <= 3 && close(r.villager.yaw, -20) && close(r.villager.pitch, 180) && close(r.villager.head_yaw, 180)), { results })
  })
  const viewerState = (offset, o) => {
    const addedPlayer = since(offset, 'add_player', o, kit.ids.pl)
    const addedZombie = since(offset, 'add_entity', o, kit.ids.zb)
    const pm = [...(addedPlayer[0]?.packet.metadata ?? []), ...meta(offset, o, kit.ids.pl)], zm = [...(addedZombie[0]?.packet.metadata ?? []), ...meta(offset, o, kit.ids.zb)]
    const armor = armorOf(offset, o, kit.ids.pl), zarmor = armorOf(offset, o, kit.ids.zb)
    const player = addedPlayer[0]?.packet
    return { o, adds: [addedPlayer.length, addedZombie.length, since(offset, 'add_entity', o, kit.ids.vl).length], held: player?.held_item?.network_id, armor, zarmor, offhand: handOf(offset, o, kit.ids.pl, 'offhand'), yaw: player?.yaw, pitch: player?.pitch, scale: metaValue(pm, 'scale'), sneaking: flag(pm, 'sneaking'), zombieInvisible: flag(zm, 'invisible'), zombieScale: metaValue(zm, 'scale'), tag: metaValue(pm, 'score_tag'), armorPackets: since(offset, 'mob_armor_equipment', o, kit.ids.pl).length }
  }
  const lateOk = r => { const e = kit.expected; return r.adds.join() === '1,1,1' && r.held === e.newMain && r.armor?.[0] === e.newHelmet && r.armor[1] === e.chestplate && r.zarmor?.[1] === e.newChest && r.offhand === 0 && close(r.yaw, 90) && close(r.pitch, 30) && r.scale === 2 && r.sneaking && r.zombieInvisible && r.zombieScale === 2 && r.tag === 'score-' + kit.ids.pl && r.armorPackets === 1 }
  await scenario('kit-arm-swing', async () => {
    const offset = wire.length
    await command('kit swing'); await sleep(1200)
    const results = observers.map(o => ({ o, events: [kit.ids.pl, kit.ids.zb, kit.ids.vl].map(id => since(offset, 'entity_event', o, id).map(e => e.packet.event_id)) }))
    check('kit-arm-swing', results.every(r => r.events[0].join() === 'arm_swing' && r.events[1].join() === 'arm_swing' && r.events[2].length === 0), { results })
  })
  await scenario('kit-new-viewer', async () => {
    await command('far NpcProbeB'); await sleep(1800)
    const offset = wire.length
    await command('near NpcProbeB'); await sleep(1800)
    check('kit-new-viewer', lateOk(viewerState(offset, 'NpcProbeB')), { state: viewerState(offset, 'NpcProbeB') })
  })
  await scenario('kit-reconnect', async () => {
    send({ player: 'NpcProbeB', action: 'close' }); await sleep(700)
    const offset = wire.length
    send({ player: 'NpcProbeB', action: 'reconnect' }); await sleep(3500)
    s = await snapshot('20-kit-reconnect')
    check('kit-reconnect', lateOk(viewerState(offset, 'NpcProbeB')) && active(s).every(n => n.viewers.length === 2), { state: viewerState(offset, 'NpcProbeB') })
  })
  await scenario('kit-static-sleep', async () => {
    await sleep(2000)
    s = await snapshot('21-kit-static-sleep')
    check('kit-static-sleep', active(s).length === 3 && active(s).every(n => n.viewers.length === 2 && n.queued === false), { queued: active(s).map(n => n.queued) })
  })
  await scenario('kit-reset', async () => {
    const offset = wire.length
    await command('kit reset'); await sleep(1200)
    const results = observers.map(o => ({ o, scales: [kit.ids.pl, kit.ids.zb, kit.ids.vl].map(id => metaValue(meta(offset, o, id), 'scale')), sneaking: flag(meta(offset, o, kit.ids.pl), 'sneaking'), invisible: flag(meta(offset, o, kit.ids.zb), 'invisible'), player: since(offset, 'move_entity', o, kit.ids.pl).at(-1)?.packet.rotation }))
    check('kit-reset', results.every(r => r.scales.every(v => v === 1) && !r.sneaking && !r.invisible && close(r.player.pitch, 0) && close(r.player.yaw, 0)), { results })
  })
  await scenario('persistent-reload', async () => {
    await command('persist')
    const old = active(await snapshot('22-permanent')).map(n => n.id)
    await command('far NpcProbeA'); await command('far NpcProbeB'); await sleep(1800)
    await command('persist-reload'); await sleep(1000)
    const offset = wire.length
    await command('near NpcProbeA'); await command('near NpcProbeB'); await sleep(2500)
    s = await snapshot('23-permanent-restored')
    const rows = active(s)
    check('persistent-reload', s.error === null && rows.length === 3 && rows.every(n => !old.includes(n.id) && n.viewers.length === 2) && observers.every(o => rows.every(n => since(offset, n.name === 'KitPlayer' ? 'add_player' : 'add_entity', o, n.id).length === 1)), { restored: rows })
    const restoredPlayer = rows.find(n => n.name === 'KitPlayer')
    check('persistent-equipment', restoredPlayer != null && observers.every(o => since(offset, 'add_player', o, restoredPlayer.id)[0]?.packet.held_item?.network_id === kit.expected.newMain && armorOf(offset, o, restoredPlayer.id)?.[0] === kit.expected.newHelmet))
    send({player: 'NpcProbeB', action: 'close'}); await sleep(700)
    const restoredReconnect = wire.length
    send({player: 'NpcProbeB', action: 'reconnect'}); await sleep(3500)
    s = await snapshot('23b-permanent-reconnect')
    check('persistent-reconnect', active(s).length === 3 && active(s).every(n => n.viewers.length === 2 && since(restoredReconnect, n.name === 'KitPlayer' ? 'add_player' : 'add_entity', 'NpcProbeB', n.id).length === 1))
    const removed = wire.length
    const ids = rows.map(n => n.id)
    await command('persist-delete'); await sleep(1000)
    s = await snapshot('24-permanent-deleted')
    check('persistent-delete', s.error === null && s.count === 0 && observers.every(o => ids.every(id => since(removed, 'remove_entity', o, id).length === 1)))
    send({player: 'NpcProbeB', action: 'close'}); await sleep(700)
    const reconnect = wire.length
    send({player: 'NpcProbeB', action: 'reconnect'}); await sleep(3500)
    s = await snapshot('25-permanent-deleted-reconnect')
    check('persistent-delete-reconnect', s.count === 0 && !wire.slice(reconnect).some(e => ['add_player', 'add_entity'].includes(e.event) && ids.includes(Number(e.packet?.runtime_id))))
  })
  check('client-errors' , !wire.some(e => ['error', 'action_error', 'kick'].includes(e.event)), { errors: wire.filter(e => ['error', 'action_error', 'kick'].includes(e.event)) })
  fs.writeFileSync(path.join(outputDir, 'latest-suite.json'), JSON.stringify({ evidence, results, childPid: child.pid }, null, 2))
  finished = true
  console.log('SUITE_FINISHED_CLIENTS_STAY_CONNECTED ' + evidence)
}
main().catch(error => { check('fatal', false, { error: error.stack }); console.log('SUITE_FAILED'); child.kill(); process.exitCode = 1 })
readline.createInterface({ input: process.stdin }).on('line', line => {
  if (line !== 'close') { child.stdin.write(line + '\n'); return }
  const done = () => output.end(() => process.exit(finished && results.every(r => r.result === 'PASS') ? 0 : 1))
  if (child.exitCode !== null) done()
  else { child.once('exit', done); child.kill() }
})
