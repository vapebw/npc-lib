# Real UDP client research

This scratch harness is outside the distributable library. It connects through
RakNet/UDP and Bedrock login; it does not construct Quark players or call server
events. It is an automated protocol client, not the official Minecraft visual
client. A serializer check alone does not prove interoperability.

Verified local prerequisites: Node v24.18.0. Dependencies: bedrock-protocol3.61.0,
minecraft-data3.119.0. Installation used local npm with `--ignore-scripts` and no
global packages. `jsp-raknet` and `skipPing:true` avoid native RakNet addons.

```powershell
node work/p1p2-client-research/client.cjs --verify
node work/p1p2-client-research/client.cjs
```

The second command starts two clients named NpcProbeA and NpcProbeB against
127.0.0.1:29184, with self-signed offline identity. Only use against the isolated
test fixture with authentication disabled. Version1.26.51 maps to protocol2193.
Override NPC_TEST_PORT, NPC_TEST_PLAYERS (comma separated), NPC_TEST_SECONDS.

Actions are newline JSON on stdin:

```json
{"player":"NpcProbeA","action":"command","command":"npctest"}
{"player":"NpcProbeA","action":"state"}
{"player":"NpcProbeA","action":"attack","target":"123"}
{"player":"NpcProbeB","action":"interact","targetName":"NPC name"}
{"player":"NpcProbeA","action":"close"}
{"player":"NpcProbeA","action":"reconnect"}
```

Attack and interact serialize the actual inventory_transaction packet,
item_use_on_entity transaction type, with action_type attack/interact. They use
the latest server-reported player position and an empty hotbar slot0; the fixture
must keep that slot empty and the actor within valid reach. Use actual server
teleport commands for chunk transitions. This harness does not implement the
client movement prediction loop. `command_request` requires permissions from the
fixture. A server console command can also control the scenario.

Wire events are appended to wire-events.jsonl. NPC spawns can be identified by
add_player username/runtime_id or add_entity runtime_id and metadata. The log
captures received packets, not visual rendering or physical click behavior.
Large binary fields and strings over4096characters are recorded by length and
SHA256 to keep skin payloads from overwhelming the log.

Primary implementation references inspected:

- https://github.com/PrismarineJS/bedrock-protocol/blob/master/src/createClient.js
- https://github.com/PrismarineJS/bedrock-protocol/blob/master/src/client.js
- https://github.com/PrismarineJS/bedrock-protocol/blob/master/src/handshake/login.js
- https://github.com/PrismarineJS/bedrock-protocol/blob/master/docs/API.md
- https://github.com/PrismarineJS/minecraft-data/blob/master/data/bedrock/common/protocolVersions.json

The installed authoritative packet schemas are
node_modules/minecraft-data/minecraft-data/data/bedrock/1.26.51/proto.yml and
types.yml. The --verify check serialized both entity actions and command_request
without opening network connections, exit0 on2026-11-02. No E2E success is implied.

Other local candidates inspected read-only: zig-protocol Current2193 but README
UNFINISHED; network-zig is a memory-only session engine requiring host I/O and
client orchestration; raknet-zig implements a carrier, not a gameplay client.
Local gophertunnel is2169, current upstream master is2225; neither is assumed
compatible with2193 without an appropriate pinned implementation/profile.
