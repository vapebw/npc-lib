const path = require('node:path')
const bedrock = require('bedrock-protocol')

const VERSION = '1.26.51'

// one place for the connection options shared by the functional client and the benchmark viewers
function openSession(name, { port = 29184, profilesFolder }) {
  return bedrock.createClient({
    host: '127.0.0.1', port, version: VERSION, transport: 'raknet', offline: true, username: name,
    raknetBackend: 'raknet-native', useRaknetWorkers: false, skinData: { ProfileHash: '' }, skipPing: true,
    profilesFolder: profilesFolder ?? path.join(__dirname, '../../work/wire/client/auth-cache'), autoInitPlayer: true, conLog: null
  })
}

module.exports = { openSession, VERSION }
