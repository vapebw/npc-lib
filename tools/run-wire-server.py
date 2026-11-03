from pathlib import Path
import os,subprocess,shutil,threading,json,sys
r=Path(__file__).resolve().parent.parent; base=r/'work/p1p2'; data=base/'wire-server'; plugins=data/'plugins'; plugins.mkdir(parents=True,exist_ok=True);(base/'evidence').mkdir(parents=True,exist_ok=True)
bin=r/'work/quark-runtime/bin/php'; env=os.environ.copy();env['PHP_INI_SCAN_DIR']='';env['OPENSSL_CONF']=str(bin/'extras/ssl/openssl.cnf');env['OPENSSL_MODULES']=str(bin/'extras/ssl');env['NPCLIB_WIRE']='1';env.pop('NPCLIB_SUITE',None);env.pop('COMPOSER',None)
prefix=[str(bin/'php.exe'),'-c',str(bin/'php.ini'),'-d','extension_dir='+str(bin/'ext')]
init='require $argv[1]; (new \\quark\\FilesystemMigration(new \\quark\\FilesystemPaths($argv[2], $argv[3])))->prepare();'
subprocess.run(prefix+['-r',init,str(r/'work/quark/vendor/autoload.php'),str(data),str(plugins)],cwd=r,env=env,check=True)
shutil.copy2(r/'work/artifacts/NpcLibP0Consumer.phar',plugins/'NpcLibP0Consumer.phar')
c=(r/'work/quark/resources/server.toml').read_text(encoding='utf8').replace('\n[server]\n', "\n[server]\nserver-ip = '127.0.0.1'\n")
for old,new in {"transport.name = 'nethernet'":"transport.name = 'raknet'",'transport.nethernet.lan-discovery = true':'transport.nethernet.lan-discovery = false','server-port = 19132':'server-port = 29184','server-portv6 = 19133':'server-portv6 = 29185','enable-ipv6 = true':'enable-ipv6 = false','enable-query = true':'enable-query = false','enable-dev-builds = false':'enable-dev-builds = true',"async-workers = 'auto'":'async-workers = 2',"level-type = 'DEFAULT'":"level-type = 'FLAT'",'view-distance = 16':'view-distance = 4','xbox-auth = true':'xbox-auth = false'}.items():c=c.replace(old,new)
(data/'config/server.toml').write_text(c,encoding='utf8')
cmd=prefix+[str(r/'work/quark/src/Quark.php'),'--no-wizard','--disable-ansi','--disable-readline','--data='+str(data),'--plugins='+str(plugins),'--server.server-ip=127.0.0.1']
(base/'evidence/wire-server-command.json').write_text(json.dumps(cmd,indent=2),encoding='utf8')
p=subprocess.Popen(cmd,cwd=r,env=env,stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.STDOUT)
def output():
 with (base/'evidence/wire-server-console.log').open('wb') as f:
  for line in p.stdout:f.write(line);f.flush();print(line.decode('utf8',errors='replace'),end='',flush=True)
def input_loop():
 for line in sys.stdin:
  if p.poll() is not None:break
  p.stdin.write(line.encode('utf8'));p.stdin.flush()
a=threading.Thread(target=output);a.start();threading.Thread(target=input_loop,daemon=True).start()
code=p.wait();a.join();(base/'evidence/wire-server-exit.json').write_text(json.dumps({'exit':code}),encoding='utf8');print('SERVER_EXIT',code,flush=True);raise SystemExit(code)
