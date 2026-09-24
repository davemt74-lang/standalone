<?php
declare(strict_types=1);

function ai_usage_ready(PDO $pdo): bool {
    try{
        return subscriptions_ready($pdo)
            && installer_table_exists($pdo,'ai_usage_events')
            && installer_table_exists($pdo,'ai_usage_adjustments');
    }catch(Throwable $e){return false;}
}
function ai_usage_reservations_ready(PDO $pdo): bool {
    try{return ai_usage_ready($pdo)&&installer_table_exists($pdo,'ai_usage_reservations');}
    catch(Throwable $e){return false;}
}
function ai_usage_estimate_tokens(string $text): int {
    if($text==='')return 0;
    return max(1,(int)ceil(mb_strlen($text,'UTF-8')/4));
}
function ai_usage_refresh_account_period(PDO $pdo,array $account): array {
    if(empty($account['id'])||empty($account['period_start'])||empty($account['period_end']))return $account;
    if(($account['billing_source']??'manual')==='stripe')return $account;
    $tz=new DateTimeZone('UTC');$today=new DateTimeImmutable('today',$tz);$end=new DateTimeImmutable((string)$account['period_end'].' 00:00:00',$tz);
    if($today<$end)return $account;
    $start=new DateTimeImmutable((string)$account['period_start'].' 00:00:00',$tz);$guard=0;
    while($today>=$end&&$guard<240){$start=$end;$pair=subscription_period_from($start);$end=new DateTimeImmutable($pair[1].' 00:00:00',$tz);$guard++;}
    if($guard>=240)throw new RuntimeException('Account billing period could not be advanced safely.');
    $pdo->prepare('UPDATE accounts SET period_start=?,period_end=? WHERE id=?')->execute([$start->format('Y-m-d'),$end->format('Y-m-d'),(int)$account['id']]);
    $account['period_start']=$start->format('Y-m-d');$account['period_end']=$end->format('Y-m-d');
    return $account;
}
function ai_usage_account_by_id(PDO $pdo,int $accountId): ?array {
    if(!subscriptions_ready($pdo))return null;
    $q=$pdo->prepare("SELECT a.*,p.public_id package_public_id,p.slug package_slug,p.name package_name,p.monthly_ai_token_allowance,p.legacy_plan_tier
      FROM accounts a JOIN subscription_packages p ON p.id=a.package_id WHERE a.id=? LIMIT 1");$q->execute([$accountId]);$a=$q->fetch();
    return $a?ai_usage_refresh_account_period($pdo,$a):null;
}
function ai_usage_account_for_user(PDO $pdo,int $userId): ?array {
    if(!subscriptions_ready($pdo))return null;
    $a=subscription_ensure_user_account($pdo,$userId);
    return ai_usage_refresh_account_period($pdo,$a);
}
function ai_usage_classification(?array $user,string $initiatedBy,string $taskType): array {
    if($taskType==='deployment_shadow'||$initiatedBy==='system'||!$user)return ['system',0];
    if($initiatedBy==='admin'||($user['role']??'')==='admin')return ['admin',0];
    return ['account',1];
}
function ai_usage_account_summary(PDO $pdo,int $accountId): array {
    $account=ai_usage_account_by_id($pdo,$accountId);if(!$account)throw new RuntimeException('Account not found.');
    $used=0;$system=0;$admin=0;$adjustment=0;$reserved=0;
    if(ai_usage_ready($pdo)){
        $q=$pdo->prepare("SELECT
          COALESCE(SUM(CASE WHEN chargeable=1 THEN total_tokens ELSE 0 END),0) billable_tokens,
          COALESCE(SUM(CASE WHEN usage_class='system' THEN total_tokens ELSE 0 END),0) system_tokens,
          COALESCE(SUM(CASE WHEN usage_class='admin' THEN total_tokens ELSE 0 END),0) admin_tokens
          FROM ai_usage_events WHERE account_id=? AND period_start=? AND period_end=?");
        $q->execute([(int)$account['id'],$account['period_start'],$account['period_end']]);$r=$q->fetch()?:[];
        $used=(int)($r['billable_tokens']??0);$system=(int)($r['system_tokens']??0);$admin=(int)($r['admin_tokens']??0);
        $q=$pdo->prepare('SELECT COALESCE(SUM(token_delta),0) FROM ai_usage_adjustments WHERE account_id=? AND period_start=? AND period_end=?');
        $q->execute([(int)$account['id'],$account['period_start'],$account['period_end']]);$adjustment=(int)$q->fetchColumn();
        if(ai_usage_reservations_ready($pdo)){
            $q=$pdo->prepare("SELECT COALESCE(SUM(reserved_tokens),0) FROM ai_usage_reservations WHERE account_id=? AND period_start=? AND period_end=? AND status='reserved' AND expires_at>NOW()");
            $q->execute([(int)$account['id'],$account['period_start'],$account['period_end']]);$reserved=(int)$q->fetchColumn();
        }
    }
    $packageBase=$account['monthly_ai_token_allowance']===null?null:(int)$account['monthly_ai_token_allowance'];$entitlementBase=$packageBase;$entitlementSource='package';
    if(function_exists('account_admin_ready')&&account_admin_ready($pdo)){try{$ent=account_admin_effective_entitlements($pdo,(int)$account['id']);$entitlementBase=$ent['values']['monthly_ai_token_allowance'];$entitlementSource=$ent['sources']['monthly_ai_token_allowance']??'package';}catch(Throwable $e){}}
    $periodBase=$entitlementBase;if(function_exists('ai_overage_period_allowance'))$periodBase=ai_overage_period_allowance($pdo,$account,$entitlementBase);$effective=$periodBase===null?null:max(0,(int)$periodBase+$adjustment);$remaining=$effective===null?null:max(0,$effective-$used-$reserved);$overage=$effective===null?0:max(0,$used-$effective);
    return ['account'=>$account,'package_allowance'=>$packageBase,'base_allowance'=>$entitlementBase,'entitlement_source'=>$entitlementSource,'adjustment_tokens'=>$adjustment,'effective_allowance'=>$effective,'used_tokens'=>$used,'reserved_tokens'=>$reserved,'remaining_tokens'=>$remaining,'overage_tokens'=>$overage,'system_tokens'=>$system,'admin_tokens'=>$admin];
}
function ai_usage_summary_for_user(PDO $pdo,int $userId): ?array {
    $account=ai_usage_account_for_user($pdo,$userId);return $account?ai_usage_account_summary($pdo,(int)$account['id']):null;
}
function ai_usage_assert_can_run(PDO $pdo,?array $user,string $initiatedBy,string $taskType): void {
    if(!ai_usage_ready($pdo))return;
    [$class,$chargeable]=ai_usage_classification($user,$initiatedBy,$taskType);if(!$chargeable||!$user)return;
    $summary=ai_usage_summary_for_user($pdo,(int)$user['id']);if(!$summary)return;$account=$summary['account'];
    if(($account['status']??'active')!=='active'||in_array((string)($account['subscription_status']??''),['paused','canceled'],true))throw new RuntimeException('This account is not active for AI usage.');
    if($summary['effective_allowance']!==null&&$summary['remaining_tokens']<=0){if(function_exists('ai_overage_assert_account_can_run')&&ai_overage_ready($pdo)){ai_overage_assert_account_can_run($pdo,(int)$account['id']);return;}throw new RuntimeException('This account has used its monthly AI token allowance. An administrator can change the package or add an account credit.');}
}
function ai_usage_reserve_run(PDO $pdo,int $runId,?array $user,string $initiatedBy,string $taskType,string $system,string $prompt,int $modelMaxOutputTokens): ?array {
    if(!ai_usage_reservations_ready($pdo))return null;
    [$class,$chargeable]=ai_usage_classification($user,$initiatedBy,$taskType);if(!$chargeable||!$user)return null;
    $account=ai_usage_account_for_user($pdo,(int)$user['id']);if(!$account)return null;$accountId=(int)$account['id'];
    return app_with_advisory_lock($pdo,'ai-usage-account',$accountId,function()use($pdo,$runId,$user,$accountId,$system,$prompt,$modelMaxOutputTokens){
        $q=$pdo->prepare('SELECT * FROM ai_usage_reservations WHERE ai_run_id=? LIMIT 1');$q->execute([$runId]);if($existing=$q->fetch())return $existing;
        $summary=ai_usage_account_summary($pdo,$accountId);$account=$summary['account'];
        if(($account['status']??'active')!=='active'||in_array((string)($account['subscription_status']??''),['paused','canceled'],true))throw new RuntimeException('This account is not active for AI usage.');
        $inputUpper=max(1,strlen($system."\n".$prompt)+64);
        $providerMax=max(256,min(16384,$modelMaxOutputTokens));
        $maxOutput=$providerMax;$effective=$summary['effective_allowance'];
        if($effective!==null){
            $remaining=(int)$summary['remaining_tokens'];$overageEnabled=false;
            if(function_exists('ai_overage_policy')&&ai_overage_ready($pdo)){try{$overageEnabled=!empty(ai_overage_policy($pdo,$accountId)['enabled']);}catch(Throwable $ignored){}}
            if($overageEnabled){ai_overage_assert_projected_request($pdo,$accountId,$inputUpper,$providerMax,$remaining);}
            else{if($remaining<=$inputUpper+64)throw new RuntimeException('This account does not have enough remaining AI tokens for this request.');$maxOutput=min($providerMax,$remaining-$inputUpper);if($maxOutput<64)throw new RuntimeException('This account does not have enough remaining AI tokens for this request.');}
        }
        $reserved=$inputUpper+$maxOutput;$expires=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->modify('+2 hours')->format('Y-m-d H:i:s');
        $pdo->prepare("INSERT INTO ai_usage_reservations(public_id,ai_run_id,account_id,user_id,period_start,period_end,reserved_tokens,max_output_tokens,status,expires_at) VALUES(?,?,?,?,?,?,?,?, 'reserved',?)")
          ->execute([ulid_like(),$runId,$accountId,(int)$user['id'],$account['period_start'],$account['period_end'],$reserved,$maxOutput,$expires]);
        $q=$pdo->prepare('SELECT * FROM ai_usage_reservations WHERE ai_run_id=? LIMIT 1');$q->execute([$runId]);return $q->fetch()?:throw new RuntimeException('AI usage reservation could not be loaded.');
    },5);
}
function ai_usage_release_run(PDO $pdo,int $runId,string $reason='provider_failed'): void {
    if(!ai_usage_reservations_ready($pdo))return;
    $pdo->prepare("UPDATE ai_usage_reservations SET status='released',released_at=NOW(),release_reason=? WHERE ai_run_id=? AND status='reserved'")
      ->execute([mb_substr(trim($reason)?:'released',0,120),$runId]);
}
function ai_usage_settle_run(PDO $pdo,int $runId,int $actualTokens): void {
    if(!ai_usage_reservations_ready($pdo))return;
    $pdo->prepare("UPDATE ai_usage_reservations SET status='settled',actual_tokens=?,settled_at=NOW(),release_reason=NULL WHERE ai_run_id=? AND status='reserved'")
      ->execute([max(0,$actualTokens),$runId]);
}
function ai_usage_cost_micros(array $model,int $inputTokens,int $outputTokens): ?int {
    $in=$model['input_cost_per_million_usd']??null;$out=$model['output_cost_per_million_usd']??null;
    if($in===null&&$out===null)return null;
    return max(0,(int)round($inputTokens*(float)($in??0)+$outputTokens*(float)($out??0)));
}
function ai_usage_record_completed_run(PDO $pdo,int $runId,?array $user,string $initiatedBy,array $generated,string $system,string $prompt): ?array {
    if(!ai_usage_ready($pdo))return null;
    $q=$pdo->prepare("SELECT r.id,r.public_id,r.user_id,r.task_type,r.model_id,r.scope_type,r.scope_public_id,m.provider_id,m.input_cost_per_million_usd,m.output_cost_per_million_usd
      FROM ai_runs r LEFT JOIN ai_models m ON m.id=r.model_id WHERE r.id=? LIMIT 1");$q->execute([$runId]);$run=$q->fetch();if(!$run)throw new RuntimeException('AI run is unavailable for usage metering.');
    $q=$pdo->prepare('SELECT * FROM ai_usage_events WHERE ai_run_id=? LIMIT 1');$q->execute([$runId]);if($existing=$q->fetch()){ai_usage_settle_run($pdo,$runId,(int)$existing['total_tokens']);return $existing;}
    [$class,$chargeable]=ai_usage_classification($user,$initiatedBy,(string)$run['task_type']);
    $account=null;if(!empty($run['user_id']))$account=ai_usage_account_for_user($pdo,(int)$run['user_id']);
    $providerIn=$generated['input_tokens']??null;$providerOut=$generated['output_tokens']??null;
    $input=$providerIn===null?ai_usage_estimate_tokens($system."
".$prompt):max(0,(int)$providerIn);
    $output=$providerOut===null?ai_usage_estimate_tokens((string)($generated['text']??'')):max(0,(int)$providerOut);
    $tokenSource=$providerIn!==null&&$providerOut!==null?'provider':(($providerIn===null&&$providerOut===null)?'estimated':'mixed');
    $cost=ai_usage_cost_micros($run,$input,$output);$meta=['scope_type'=>$run['scope_type'],'scope_public_id'=>$run['scope_public_id'],'token_source'=>$tokenSource];
    $pdo->beginTransaction();try{
        try{
            $pdo->prepare("INSERT INTO ai_usage_events(public_id,ai_run_id,account_id,user_id,provider_id,model_id,initiated_by,usage_class,chargeable,task_type,token_source,input_tokens,output_tokens,total_tokens,estimated_cost_micros,period_start,period_end,metadata_json)
              VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
                ulid_like(),$runId,$account?(int)$account['id']:null,$run['user_id']?(int)$run['user_id']:null,$run['provider_id']?(int)$run['provider_id']:null,$run['model_id']?(int)$run['model_id']:null,
                $initiatedBy,$class,$chargeable,(string)$run['task_type'],$tokenSource,$input,$output,$input+$output,$cost,$account['period_start']??null,$account['period_end']??null,json_encode($meta,JSON_UNESCAPED_SLASHES)
              ]);$eventId=(int)$pdo->lastInsertId();
        }catch(PDOException $e){
            $driver=(int)($e->errorInfo[1]??0);if((string)$e->getCode()!=='23000'||$driver!==1062)throw $e;
            $q=$pdo->prepare('SELECT id FROM ai_usage_events WHERE ai_run_id=? LIMIT 1');$q->execute([$runId]);$eventId=(int)($q->fetchColumn()?:0);if(!$eventId)throw $e;
        }
        ai_usage_settle_run($pdo,$runId,$input+$output);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    $q=$pdo->prepare('SELECT * FROM ai_usage_events WHERE id=?');$q->execute([$eventId]);$event=$q->fetch()?:null;if($event&&function_exists('ai_overage_record_usage_event')&&ai_overage_ready($pdo))ai_overage_record_usage_event($pdo,(int)$event['id']);return $event;
}
function ai_usage_admin_adjust(PDO $pdo,array $admin,string $accountPublicId,int $tokenDelta,string $reason): array {
    if(($admin['role']??'')!=='admin')throw new RuntimeException('Administrator access required.');
    if(!ai_usage_ready($pdo))throw new RuntimeException('AI usage metering requires the latest database upgrade.');
    if($tokenDelta===0)throw new InvalidArgumentException('Token adjustment must be non-zero.');$reason=trim($reason);if($reason==='')throw new InvalidArgumentException('A reason is required for token adjustments.');
    $q=$pdo->prepare('SELECT id FROM accounts WHERE public_id=? LIMIT 1');$q->execute([$accountPublicId]);$id=(int)($q->fetchColumn()?:0);if(!$id)throw new RuntimeException('Account not found.');
    $summary=ai_usage_account_summary($pdo,$id);$type=$tokenDelta>0?'credit':'debit';
    $pdo->prepare("INSERT INTO ai_usage_adjustments(public_id,account_id,actor_user_id,adjustment_type,token_delta,period_start,period_end,reason,metadata_json) VALUES(?,?,?,?,?,?,?,?,?)")
      ->execute([ulid_like(),$id,(int)$admin['id'],$type,$tokenDelta,$summary['account']['period_start'],$summary['account']['period_end'],$reason,json_encode(['source'=>'admin_usage'],JSON_UNESCAPED_SLASHES)]);
    return ai_usage_account_summary($pdo,$id);
}
function ai_usage_admin_accounts(PDO $pdo,int $limit=250): array {
    if(!ai_usage_ready($pdo))return [];$limit=max(1,min(500,$limit));
    $rows=$pdo->query("SELECT a.id,a.public_id,a.name,a.personal_user_id,u.username,u.display_name,p.name package_name,p.slug package_slug
      FROM accounts a LEFT JOIN users u ON u.id=a.personal_user_id JOIN subscription_packages p ON p.id=a.package_id
      WHERE a.status<>'closed' ORDER BY a.updated_at DESC,a.id DESC LIMIT ".$limit)->fetchAll()?:[];
    foreach($rows as &$row){$row['usage']=ai_usage_account_summary($pdo,(int)$row['id']);}unset($row);return $rows;
}
function ai_usage_recent_events(PDO $pdo,int $limit=100): array {
    if(!ai_usage_ready($pdo))return [];$limit=max(1,min(500,$limit));
    return $pdo->query("SELECT e.*,a.public_id account_public_id,a.name account_name,u.username,u.display_name,m.display_name model_name,p.label provider_label
      FROM ai_usage_events e LEFT JOIN accounts a ON a.id=e.account_id LEFT JOIN users u ON u.id=e.user_id LEFT JOIN ai_models m ON m.id=e.model_id LEFT JOIN ai_providers p ON p.id=e.provider_id
      ORDER BY e.created_at DESC,e.id DESC LIMIT ".$limit)->fetchAll()?:[];
}
function ai_usage_recent_adjustments(PDO $pdo,int $limit=100): array {
    if(!ai_usage_ready($pdo))return [];$limit=max(1,min(500,$limit));
    return $pdo->query("SELECT x.*,a.public_id account_public_id,a.name account_name,u.username actor_username
      FROM ai_usage_adjustments x JOIN accounts a ON a.id=x.account_id LEFT JOIN users u ON u.id=x.actor_user_id
      ORDER BY x.created_at DESC,x.id DESC LIMIT ".$limit)->fetchAll()?:[];
}
