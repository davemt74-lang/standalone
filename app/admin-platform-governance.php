<?php
declare(strict_types=1);

function admin_platform_ready(PDO $pdo): bool {
    try{foreach(['admin_platform_features','admin_platform_modules','admin_platform_integrations','admin_platform_snapshots','admin_platform_events'] as $t)if(!installer_table_exists($pdo,$t))return false;return true;}catch(Throwable $e){return false;}
}
function admin_platform_reason(string $v,string $fallback='Platform governance record updated.'): string {$v=trim($v);if($v==='')$v=$fallback;return mb_substr($v,0,1000);}
function admin_platform_json_array(mixed $v): array {
    if(is_array($v))return array_values(array_unique(array_filter(array_map(fn($x)=>trim((string)$x),$v),fn($x)=>$x!=='')));
    $s=trim((string)$v);if($s==='')return [];try{$j=json_decode($s,true,512,JSON_THROW_ON_ERROR);if(is_array($j))return admin_platform_json_array($j);}catch(Throwable $e){}
    return admin_platform_json_array(preg_split('/[\s,]+/',$s)?:[]);
}
function admin_platform_event(PDO $pdo,array $admin,string $eventType,string $subjectType,?string $subjectPublicId,mixed $before,mixed $after,string $reason,?string $correlation=null): void {
    if(!admin_platform_ready($pdo))return;$enc=fn(mixed $v)=>$v===null?null:json_encode($v,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $public=ulid_like();$pdo->prepare("INSERT INTO admin_platform_events(public_id,actor_user_id,event_type,subject_type,subject_public_id,before_json,after_json,reason,correlation_id) VALUES(?,?,?,?,?,?,?,?,?)")->execute([$public,(int)$admin['id'],mb_substr($eventType,0,100),mb_substr($subjectType,0,80),$subjectPublicId!==null?mb_substr($subjectPublicId,0,255):null,$enc($before),$enc($after),admin_platform_reason($reason),$correlation!==null?mb_substr($correlation,0,96):null]);
    if(function_exists('admin_security_audit_record')&&admin_security_ready($pdo))admin_security_audit_record($pdo,$admin,['subject_type'=>$subjectType,'subject_public_id'=>$subjectPublicId,'event_type'=>$eventType,'source_domain'=>'platform','source_event_public_id'=>$public,'sensitivity'=>'restricted','before'=>$before,'after'=>$after,'metadata'=>$correlation?['correlation_id'=>$correlation]:null,'correlation_id'=>$correlation,'reason'=>$reason]);
}
function admin_platform_features(PDO $pdo): array {
    if(!admin_platform_ready($pdo))return [];$rows=$pdo->query("SELECT * FROM admin_platform_features ORDER BY category,name,id")->fetchAll()?:[];foreach($rows as &$r){foreach(['packages_json'=>'packages','accounts_json'=>'accounts','dependencies_json'=>'dependencies'] as $f=>$k){try{$r[$k]=json_decode((string)($r[$f]??'[]'),true,512,JSON_THROW_ON_ERROR)?:[];}catch(Throwable $e){$r[$k]=[];}}}unset($r);return $rows;
}
function admin_platform_feature(PDO $pdo,string $identifier): ?array {
    if(!admin_platform_ready($pdo))return null;$q=$pdo->prepare("SELECT * FROM admin_platform_features WHERE public_id=? OR feature_key=? LIMIT 1");$q->execute([$identifier,$identifier]);$r=$q->fetch();if(!$r)return null;foreach(['packages_json'=>'packages','accounts_json'=>'accounts','dependencies_json'=>'dependencies'] as $f=>$k){try{$r[$k]=json_decode((string)($r[$f]??'[]'),true,512,JSON_THROW_ON_ERROR)?:[];}catch(Throwable $e){$r[$k]=[];}}return $r;
}
function admin_platform_feature_effective(PDO $pdo,array $feature,?array $account=null,array $seen=[]): array {
    $key=(string)$feature['feature_key'];if(isset($seen[$key]))return ['enabled'=>false,'reason'=>'dependency_cycle','rollout_bucket'=>null];$seen[$key]=true;
    if(in_array((string)$feature['lifecycle_status'],['paused','retired'],true)||!(int)$feature['default_enabled'])return ['enabled'=>false,'reason'=>'disabled_by_governance','rollout_bucket'=>null];
    $packages=(array)($feature['packages']??[]);$accounts=(array)($feature['accounts']??[]);
    if($account){
        $accountPublic=(string)($account['public_id']??'');$packagePublic=(string)($account['package_public_id']??'');
        if($accounts&&!in_array($accountPublic,$accounts,true))return ['enabled'=>false,'reason'=>'account_not_eligible','rollout_bucket'=>null];
        if($packages&&!in_array($packagePublic,$packages,true))return ['enabled'=>false,'reason'=>'package_not_eligible','rollout_bucket'=>null];
    }elseif($accounts||$packages)return ['enabled'=>false,'reason'=>'scoped_feature_requires_account','rollout_bucket'=>null];
    foreach((array)($feature['dependencies']??[]) as $depKey){$dep=admin_platform_feature($pdo,(string)$depKey);if(!$dep)continue;$eff=admin_platform_feature_effective($pdo,$dep,$account,$seen);if(!$eff['enabled'])return ['enabled'=>false,'reason'=>'dependency_disabled:'.$depKey,'rollout_bucket'=>null];}
    $roll=max(0,min(100,(int)$feature['rollout_percent']));if($roll>=100)return ['enabled'=>true,'reason'=>'eligible','rollout_bucket'=>0];if($roll<=0)return ['enabled'=>false,'reason'=>'rollout_zero','rollout_bucket'=>99];
    $seed=$key.'|'.(string)($account['public_id']??'global');$bucket=hexdec(substr(hash('sha256',$seed),0,8))%100;return ['enabled'=>$bucket<$roll,'reason'=>$bucket<$roll?'rollout_eligible':'rollout_bucket_excluded','rollout_bucket'=>$bucket];
}
function admin_platform_account_features(PDO $pdo,array $account): array {
    $out=[];foreach(admin_platform_features($pdo) as $feature){$feature['effective']=admin_platform_feature_effective($pdo,$feature,$account);$out[]=$feature;}return $out;
}
function admin_platform_modules(PDO $pdo): array {
    if(!admin_platform_ready($pdo))return [];$rows=$pdo->query("SELECT * FROM admin_platform_modules ORDER BY name,id")->fetchAll()?:[];foreach($rows as &$r){try{$r['dependencies']=json_decode((string)($r['dependencies_json']??'[]'),true,512,JSON_THROW_ON_ERROR)?:[];}catch(Throwable $e){$r['dependencies']=[];}}unset($r);return $rows;
}
function admin_platform_module_observed(PDO $pdo,array $module): array {
    $key=(string)$module['module_key'];$available=match($key){
        'core'=>installer_table_exists($pdo,'users')&&installer_table_exists($pdo,'annotations'),
        'research'=>installer_table_exists($pdo,'research_projects'),
        'ai'=>installer_table_exists($pdo,'ai_providers')&&function_exists('ai_run'),
        'commerce'=>function_exists('subscriptions_ready')&&subscriptions_ready($pdo),
        'admin'=>admin_access_ready($pdo)&&admin_platform_ready($pdo),
        'extension'=>is_file(dirname(__DIR__).'/extension/manifest.json'),
        default=>false,
    };
    $desired=(string)$module['desired_state'];$matches=$desired==='enabled'?$available:($desired==='disabled'?!$available:true);return ['available'=>$available,'state'=>$available?'available':'unavailable','matches_desired'=>$matches];
}
function admin_platform_integrations(PDO $pdo,array $config): array {
    if(!admin_platform_ready($pdo))return [];$rows=$pdo->query("SELECT * FROM admin_platform_integrations ORDER BY category,name,id")->fetchAll()?:[];
    $aiTypes=[];if(installer_table_exists($pdo,'ai_providers'))try{foreach($pdo->query("SELECT provider_type,COUNT(*) c FROM ai_providers WHERE enabled=1 GROUP BY provider_type")->fetchAll()?:[] as $r)$aiTypes[(string)$r['provider_type']]=(int)$r['c'];}catch(Throwable $e){}
    foreach($rows as &$r){$key=(string)$r['integration_key'];$configured=false;$detail='Not configured.';
        if($key==='stripe'){$configured=function_exists('stripe_billing_configured')&&stripe_billing_configured($pdo,$config);$detail=$configured?'Stripe secrets are configured in the authoritative billing settings.':'Stripe billing secrets are incomplete.';}
        elseif($key==='openai'){$configured=($aiTypes['openai']??0)>0;$detail=$configured?'At least one enabled OpenAI provider is registered.':'No enabled OpenAI provider is registered.';}
        elseif($key==='anthropic'){$configured=($aiTypes['anthropic']??0)>0;$detail=$configured?'At least one enabled Anthropic provider is registered.':'No enabled Anthropic provider is registered.';}
        elseif($key==='elevenlabs'){$cfg=(array)($config['elevenlabs']??[]);$configured=!empty($cfg['api_key'])||!empty($cfg['enabled']);$detail=$configured?'ElevenLabs configuration is present.':'ElevenLabs is not configured in server configuration.';}
        elseif($key==='google_oauth'||$key==='x_oauth'){$p=$key==='google_oauth'?'google':'x';$cfg=(array)($config['oauth'][$p]??[]);$configured=trim((string)($cfg['client_id']??''))!==''&&trim((string)($cfg['client_secret']??''))!==''&&trim((string)($cfg['redirect_uri']??''))!=='';$detail=$configured?ucfirst($p).' OAuth configuration is complete.':ucfirst($p).' OAuth configuration is incomplete.';}
        elseif($key==='vp3'){$cfg=function_exists('vp3_connector_config')?vp3_connector_config($config):[];$configured=!empty($cfg['configured']);$detail=$configured?'VP3 connector server configuration is valid.':'VP3 connector is not fully configured.';}
        elseif($key==='mail'){$configured=!empty($config['mail']['enabled']);$detail=$configured?'Outbound mail is enabled.':'Outbound mail is disabled.';}
        $r['configured']=$configured;$r['health_state']=$r['desired_state']==='disabled'?'disabled':($configured?'configured':'degraded');$r['health_detail']=$detail;
    }unset($r);return $rows;
}
function admin_platform_safe_config_summary(PDO $pdo,array $config,string $root): array {
    $base=(string)($config['app']['base_url']??'');$storage=(string)($config['storage']['private_root']??'');$schema=function_exists('app_schema_runtime_status')?app_schema_runtime_status($pdo,$root.'/database/migrations'):null;$environment=function_exists('release_environment_checks')?release_environment_checks($pdo,$config):null;$manifest=null;$manifestPath=rtrim($root,'/').'/RELEASE-MANIFEST.json';if(is_file($manifestPath))try{$manifest=json_decode((string)file_get_contents($manifestPath),true,512,JSON_THROW_ON_ERROR);}catch(Throwable $e){$manifest=null;}$latest=function_exists('release_latest_migration')?release_latest_migration($root):null;
    return [
      'app'=>['base_url'=>$base,'https'=>strtolower((string)(parse_url($base,PHP_URL_SCHEME)?:''))==='https','session_name'=>(string)($config['app']['session_name']??'annotated_session'),'encryption_key_configured'=>strlen((string)($config['app']['encryption_key']??''))>=32],
      'database'=>['driver'=>(string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME),'pending_migrations'=>$schema['pending']??[],'changed_migrations'=>$schema['changed']??[],'schema_ready'=>$schema['ready']??false],
      'storage'=>['configured'=>$storage!=='','exists'=>$storage!==''&&is_dir($storage),'writable'=>$storage!==''&&is_writable($storage),'basename'=>$storage!==''?basename(rtrim($storage,'/')):null],
      'release'=>['release'=>$manifest['release']??(defined('ANNOTATED_RELEASE')?ANNOTATED_RELEASE:null),'version'=>$manifest['version']??(defined('ANNOTATED_RELEASE_VERSION')?ANNOTATED_RELEASE_VERSION:null),'phase'=>$manifest['phase']??(defined('ANNOTATED_RELEASE_PHASE')?ANNOTATED_RELEASE_PHASE:null),'channel'=>$manifest['channel']??(defined('ANNOTATED_RELEASE_CHANNEL')?ANNOTATED_RELEASE_CHANNEL:null),'extension_version'=>$manifest['extension_version']??(defined('ANNOTATED_EXTENSION_VERSION')?ANNOTATED_EXTENSION_VERSION:null),'latest_migration'=>$manifest['latest_migration']??$latest,'package_fingerprint'=>$manifest['package_fingerprint']??null,'build_sha'=>$manifest['build_sha']??null,'manifest_present'=>$manifest!==null,'ready'=>!empty($environment['ready'])&&!empty($schema['ready'])],
    ];
}
function admin_platform_snapshot_payload(PDO $pdo,array $config,string $root): array {
    $cfg=admin_platform_safe_config_summary($pdo,$config,$root);$modules=[];foreach(admin_platform_modules($pdo) as $m){$modules[$m['module_key']]=['desired_state'=>$m['desired_state'],'version_label'=>$m['version_label'],'observed'=>admin_platform_module_observed($pdo,$m)];}
    $integrations=[];foreach(admin_platform_integrations($pdo,$config) as $i)$integrations[$i['integration_key']]=['desired_state'=>$i['desired_state'],'configured'=>$i['configured'],'health_state'=>$i['health_state']];
    $features=[];foreach(admin_platform_features($pdo) as $f)$features[$f['feature_key']]=['lifecycle_status'=>$f['lifecycle_status'],'enforcement_mode'=>$f['enforcement_mode'],'default_enabled'=>(bool)$f['default_enabled'],'rollout_percent'=>(int)$f['rollout_percent'],'packages'=>$f['packages'],'accounts'=>$f['accounts'],'dependencies'=>$f['dependencies']];
    return ['config'=>$cfg,'modules'=>$modules,'integrations'=>$integrations,'features'=>$features];
}
function admin_platform_canonicalize(mixed $value): mixed {
    if(!is_array($value))return $value;
    if(array_is_list($value))return array_map('admin_platform_canonicalize',$value);
    ksort($value,SORT_STRING);foreach($value as $key=>$item)$value[$key]=admin_platform_canonicalize($item);return $value;
}
function admin_platform_drift_material(array $payload): array {
    $cfg=(array)($payload['config']??[]);$release=(array)($cfg['release']??[]);
    $stable=[
      'config'=>[
        'app'=>(array)($cfg['app']??[]),
        'database'=>(array)($cfg['database']??[]),
        'storage'=>(array)($cfg['storage']??[]),
        'release'=>[
          'release'=>$release['release']??null,'version'=>$release['version']??null,'phase'=>$release['phase']??null,
          'channel'=>$release['channel']??null,'extension_version'=>$release['extension_version']??null,
          'latest_migration'=>$release['latest_migration']??null,'package_fingerprint'=>$release['package_fingerprint']??null,
          'build_sha'=>$release['build_sha']??null,'manifest_present'=>$release['manifest_present']??null,
        ],
      ],
      'modules'=>[],
      'integrations'=>[],
      'features'=>(array)($payload['features']??[]),
    ];
    foreach((array)($payload['modules']??[]) as $key=>$row)$stable['modules'][(string)$key]=['desired_state'=>$row['desired_state']??null,'version_label'=>$row['version_label']??null];
    foreach((array)($payload['integrations']??[]) as $key=>$row)$stable['integrations'][(string)$key]=['desired_state'=>$row['desired_state']??null];
    return $stable;
}
function admin_platform_fingerprint(array $payload): string {return hash('sha256',json_encode(admin_platform_canonicalize(admin_platform_drift_material($payload)),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));}
function admin_platform_capture_snapshot(PDO $pdo,array $config,array $admin,string $type='release_readiness',string $reason='Capture platform readiness snapshot.'): array {
    admin_access_assert_capability($pdo,$admin,'admin.platform.release');if(!admin_platform_ready($pdo))throw new RuntimeException('Admin V2.60 platform schema is unavailable.');if(!in_array($type,['configuration','release_readiness','drift_baseline'],true))throw new InvalidArgumentException('Platform snapshot type is invalid.');
    $payload=admin_platform_snapshot_payload($pdo,$config,dirname(__DIR__));$fingerprint=admin_platform_fingerprint($payload);$build=$payload['config']['release']['build_sha']??null;$public=ulid_like();$pdo->prepare("INSERT INTO admin_platform_snapshots(public_id,snapshot_type,fingerprint,snapshot_json,source_build_sha,created_by_user_id) VALUES(?,?,?,?,?,?)")->execute([$public,$type,$fingerprint,json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$build,(int)$admin['id']]);$q=$pdo->prepare("SELECT * FROM admin_platform_snapshots WHERE public_id=?");$q->execute([$public]);$row=$q->fetch()?:throw new RuntimeException('Platform snapshot could not be reloaded.');admin_platform_event($pdo,$admin,'platform_snapshot_created','platform_snapshot',$public,null,['snapshot_type'=>$type,'fingerprint'=>$fingerprint],$reason);return $row;
}
function admin_platform_latest_snapshot(PDO $pdo,string $type='drift_baseline'): ?array {
    if(!admin_platform_ready($pdo))return null;$q=$pdo->prepare("SELECT s.*,u.username created_by_username FROM admin_platform_snapshots s JOIN users u ON u.id=s.created_by_user_id WHERE s.snapshot_type=? ORDER BY s.created_at DESC,s.id DESC LIMIT 1");$q->execute([$type]);$r=$q->fetch();if(!$r)return null;try{$r['snapshot']=json_decode((string)$r['snapshot_json'],true,512,JSON_THROW_ON_ERROR);}catch(Throwable $e){$r['snapshot']=[];}return $r;
}
function admin_platform_drift(PDO $pdo,array $config): array {
    $current=admin_platform_snapshot_payload($pdo,$config,dirname(__DIR__));$fingerprint=admin_platform_fingerprint($current);$baseline=admin_platform_latest_snapshot($pdo,'drift_baseline');if(!$baseline)return ['status'=>'no_baseline','drifted'=>false,'current_fingerprint'=>$fingerprint,'baseline'=>null,'changed_sections'=>[]];
    $currentStable=admin_platform_canonicalize(admin_platform_drift_material($current));$oldStable=admin_platform_canonicalize(admin_platform_drift_material((array)($baseline['snapshot']??[])));$changed=[];foreach(['config','modules','integrations','features'] as $section)if(json_encode($oldStable[$section]??null,JSON_UNESCAPED_SLASHES)!==json_encode($currentStable[$section]??null,JSON_UNESCAPED_SLASHES))$changed[]=$section;
    return ['status'=>$changed?'drifted':'aligned','drifted'=>(bool)$changed,'current_fingerprint'=>$fingerprint,'baseline'=>$baseline,'changed_sections'=>$changed];
}
function admin_platform_metrics(PDO $pdo,array $config): array {
    $features=admin_platform_features($pdo);$modules=admin_platform_modules($pdo);$integrations=admin_platform_integrations($pdo,$config);$cfg=admin_platform_safe_config_summary($pdo,$config,dirname(__DIR__));$drift=admin_platform_drift($pdo,$config);$degraded=count(array_filter($integrations,fn($i)=>$i['health_state']==='degraded'));$moduleMismatch=0;foreach($modules as $m)if(!admin_platform_module_observed($pdo,$m)['matches_desired'])$moduleMismatch++;
    return ['features'=>count($features),'enforced_features'=>count(array_filter($features,fn($f)=>$f['enforcement_mode']==='enforce')),'paused_features'=>count(array_filter($features,fn($f)=>in_array($f['lifecycle_status'],['paused','retired'],true))),'modules'=>count($modules),'module_mismatches'=>$moduleMismatch,'integrations'=>count($integrations),'degraded_integrations'=>$degraded,'release_ready'=>!empty($cfg['release']['ready']),'schema_ready'=>!empty($cfg['database']['schema_ready']),'drift_status'=>$drift['status'],'drifted'=>$drift['drifted']];
}
function admin_platform_validate_feature_targets(PDO $pdo,array $feature,array $packages,array $accounts,array $dependencies): void {
    foreach($packages as $public){$q=$pdo->prepare("SELECT 1 FROM subscription_packages WHERE public_id=? LIMIT 1");$q->execute([$public]);if(!$q->fetchColumn())throw new InvalidArgumentException('Unknown package public ID: '.$public);}
    foreach($accounts as $public){$q=$pdo->prepare("SELECT 1 FROM accounts WHERE public_id=? LIMIT 1");$q->execute([$public]);if(!$q->fetchColumn())throw new InvalidArgumentException('Unknown account public ID: '.$public);}
    foreach($dependencies as $key){if($key===(string)$feature['feature_key'])throw new InvalidArgumentException('A feature cannot depend on itself.');if(!admin_platform_feature($pdo,$key))throw new InvalidArgumentException('Unknown feature dependency: '.$key);}
    $graph=[];foreach(admin_platform_features($pdo) as $f)$graph[(string)$f['feature_key']]=(array)$f['dependencies'];$graph[(string)$feature['feature_key']]=$dependencies;$vis=[];$stack=[];$walk=function(string $key)use(&$walk,&$graph,&$vis,&$stack){if(!empty($stack[$key]))throw new InvalidArgumentException('Feature dependency cycle detected at '.$key.'.');if(!empty($vis[$key]))return;$stack[$key]=true;foreach((array)($graph[$key]??[]) as $next)$walk((string)$next);unset($stack[$key]);$vis[$key]=true;};foreach(array_keys($graph) as $key)$walk((string)$key);
}
function admin_platform_feature_action_preview(PDO $pdo,array $admin,string $featurePublicId,array $input): array {
    admin_access_assert_capability($pdo,$admin,'admin.platform.manage');admin_access_assert_capability($pdo,$admin,'admin.actions.request');$feature=admin_platform_feature($pdo,$featurePublicId);if(!$feature)throw new RuntimeException('Platform feature not found.');
    $statusRaw=(string)($input['lifecycle_status']??$feature['lifecycle_status']);$status=in_array($statusRaw,['draft','pilot','active','paused','retired'],true)?$statusRaw:(string)$feature['lifecycle_status'];$modeRaw=(string)($input['enforcement_mode']??$feature['enforcement_mode']);$mode=in_array($modeRaw,['observe','enforce'],true)?$modeRaw:(string)$feature['enforcement_mode'];$enabled=array_key_exists('default_enabled',$input)?!empty($input['default_enabled']):(bool)$feature['default_enabled'];$roll=max(0,min(100,(int)($input['rollout_percent']??$feature['rollout_percent'])));$packages=admin_platform_json_array($input['packages']??$feature['packages']);$accounts=admin_platform_json_array($input['accounts']??$feature['accounts']);$dependencies=admin_platform_json_array($input['dependencies']??$feature['dependencies']);admin_platform_validate_feature_targets($pdo,$feature,$packages,$accounts,$dependencies);
    $reason=admin_platform_reason((string)($input['reason']??''),'Request platform feature rollout change.');$before=['lifecycle_status'=>$feature['lifecycle_status'],'enforcement_mode'=>$feature['enforcement_mode'],'default_enabled'=>(bool)$feature['default_enabled'],'rollout_percent'=>(int)$feature['rollout_percent'],'packages'=>$feature['packages'],'accounts'=>$feature['accounts'],'dependencies'=>$feature['dependencies']];$after=['lifecycle_status'=>$status,'enforcement_mode'=>$mode,'default_enabled'=>$enabled,'rollout_percent'=>$roll,'packages'=>$packages,'accounts'=>$accounts,'dependencies'=>$dependencies];
    $record=admin_ops_record_action($pdo,$admin,null,'change_platform_feature','previewed','elevated',$reason,['feature_public_id'=>$feature['public_id'],'feature_key'=>$feature['feature_key'],'before'=>$before,'after'=>$after],null);if(admin_access_ready($pdo))$record=admin_access_bind_action_policy($pdo,$record);return ['record'=>$record,'feature'=>$feature,'before'=>$before,'after'=>$after];
}
function admin_platform_execute_feature_action(PDO $pdo,array $admin,array $record): array {
    $request=json_decode((string)$record['request_json'],true)?:[];$feature=admin_platform_feature($pdo,(string)($request['feature_public_id']??''));if(!$feature)throw new RuntimeException('Platform feature no longer exists.');$after=(array)($request['after']??[]);$packages=admin_platform_json_array($after['packages']??[]);$accounts=admin_platform_json_array($after['accounts']??[]);$deps=admin_platform_json_array($after['dependencies']??[]);
    $pdo->prepare("UPDATE admin_platform_features SET lifecycle_status=?,enforcement_mode=?,default_enabled=?,rollout_percent=?,packages_json=?,accounts_json=?,dependencies_json=?,updated_by_user_id=? WHERE id=?")->execute([(string)$after['lifecycle_status'],(string)$after['enforcement_mode'],!empty($after['default_enabled'])?1:0,max(0,min(100,(int)$after['rollout_percent'])),json_encode($packages,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),json_encode($accounts,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),json_encode($deps,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),(int)$admin['id'],(int)$feature['id']]);
    $next=admin_platform_feature($pdo,(string)$feature['public_id'])??$feature;admin_platform_event($pdo,$admin,'platform_feature_changed','platform_feature',(string)$feature['public_id'],$request['before']??null,$request['after']??null,(string)$record['reason'],(string)$record['correlation_id']);return ['public_id'=>$feature['public_id'],'feature_key'=>$feature['feature_key'],'status'=>$next['lifecycle_status'],'enforcement_mode'=>$next['enforcement_mode'],'rollout_percent'=>(int)$next['rollout_percent']];
}
function admin_platform_module_update(PDO $pdo,array $admin,string $publicId,array $input): array {
    admin_access_assert_capability($pdo,$admin,'admin.platform.manage');$q=$pdo->prepare("SELECT * FROM admin_platform_modules WHERE public_id=? LIMIT 1");$q->execute([$publicId]);$row=$q->fetch();if(!$row)throw new RuntimeException('Platform module not found.');$before=['desired_state'=>$row['desired_state'],'owner_note'=>$row['owner_note']];$state=in_array((string)($input['desired_state']??$row['desired_state']),['enabled','maintenance','disabled'],true)?(string)$input['desired_state']:(string)$row['desired_state'];$note=mb_substr(trim((string)($input['owner_note']??$row['owner_note']??'')),0,255);$pdo->prepare("UPDATE admin_platform_modules SET desired_state=?,owner_note=?,updated_by_user_id=? WHERE id=?")->execute([$state,$note!==''?$note:null,(int)$admin['id'],(int)$row['id']]);$q->execute([$publicId]);$after=$q->fetch()?:$row;admin_platform_event($pdo,$admin,'platform_module_governance_changed','platform_module',$publicId,$before,['desired_state'=>$after['desired_state'],'owner_note'=>$after['owner_note']],(string)($input['reason']??'Update module governance metadata.'));return $after;
}
function admin_platform_integration_update(PDO $pdo,array $admin,string $publicId,array $input): array {
    admin_access_assert_capability($pdo,$admin,'admin.platform.manage');$q=$pdo->prepare("SELECT * FROM admin_platform_integrations WHERE public_id=? LIMIT 1");$q->execute([$publicId]);$row=$q->fetch();if(!$row)throw new RuntimeException('Platform integration not found.');$before=['desired_state'=>$row['desired_state'],'owner_note'=>$row['owner_note']];$state=in_array((string)($input['desired_state']??$row['desired_state']),['enabled','disabled'],true)?(string)$input['desired_state']:(string)$row['desired_state'];$note=mb_substr(trim((string)($input['owner_note']??$row['owner_note']??'')),0,255);$pdo->prepare("UPDATE admin_platform_integrations SET desired_state=?,owner_note=?,updated_by_user_id=? WHERE id=?")->execute([$state,$note!==''?$note:null,(int)$admin['id'],(int)$row['id']]);$q->execute([$publicId]);$after=$q->fetch()?:$row;admin_platform_event($pdo,$admin,'platform_integration_governance_changed','platform_integration',$publicId,$before,['desired_state'=>$after['desired_state'],'owner_note'=>$after['owner_note']],(string)($input['reason']??'Update integration governance metadata.'));return $after;
}
function admin_platform_events(PDO $pdo,int $limit=150): array {
    if(!admin_platform_ready($pdo))return [];$limit=max(1,min(500,$limit));return $pdo->query("SELECT e.*,u.username actor_username,u.display_name actor_display_name FROM admin_platform_events e JOIN users u ON u.id=e.actor_user_id ORDER BY e.created_at DESC,e.id DESC LIMIT ".$limit)->fetchAll()?:[];
}
function admin_platform_agent_context(PDO $pdo,array $config,array $viewer): string {
    if(($viewer['role']??'')!=='admin'||!admin_platform_ready($pdo)||!admin_access_has_capability($pdo,$viewer,'admin.platform.view'))return '';$m=admin_platform_metrics($pdo,$config);return "[ADMIN V2.60 PLATFORM GOVERNANCE — READ ONLY]\nRelease ready: ".($m['release_ready']?'yes':'no')."; schema ready: ".($m['schema_ready']?'yes':'no')."; governed features: {$m['features']} ({$m['enforced_features']} enforced, {$m['paused_features']} paused/retired); degraded integrations: {$m['degraded_integrations']}; module mismatches: {$m['module_mismatches']}; drift: {$m['drift_status']}. The Agent may explain configuration, rollout eligibility, integration health and release readiness but cannot change feature rollouts, module/integration governance, secrets, migrations, releases, workers, backups or configuration.";
}
