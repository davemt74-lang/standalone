<?php
declare(strict_types=1);

require_once __DIR__.'/access.php';
require_once __DIR__.'/conversations.php';
require_once __DIR__.'/research-knowledge.php';

function workspace_context_ref(mixed $value): string {
    $value=trim((string)$value);
    return preg_match('/^[A-Za-z0-9._:-]{1,96}$/',$value)?$value:'';
}

function workspace_context_team(PDO $pdo,array $viewer,string $publicId): ?array {
    $publicId=workspace_context_ref($publicId);if($publicId==='')return null;
    $q=$pdo->prepare("SELECT t.public_id,t.name FROM teams t JOIN team_members tm ON tm.team_id=t.id AND tm.user_id=? WHERE t.public_id=? LIMIT 1");
    $q->execute([$viewer['id'],$publicId]);$row=$q->fetch();if(!$row)return null;
    return ['public_id'=>(string)$row['public_id'],'label'=>(string)$row['name'],'url'=>'/team.php?id='.rawurlencode((string)$row['public_id'])];
}

function workspace_context_research(PDO $pdo,array $viewer,string $publicId): ?array {
    $publicId=workspace_context_ref($publicId);if($publicId==='')return null;
    $project=project_access($pdo,(int)$viewer['id'],$publicId);if(!$project)return null;
    $out=['public_id'=>(string)$project['public_id'],'label'=>(string)$project['title'],'url'=>'/research-project.php?id='.rawurlencode((string)$project['public_id'])];
    if(!empty($project['team_id'])){
        $q=$pdo->prepare("SELECT t.public_id,t.name FROM teams t JOIN team_members tm ON tm.team_id=t.id AND tm.user_id=? WHERE t.id=? LIMIT 1");
        $q->execute([$viewer['id'],$project['team_id']]);if($team=$q->fetch())$out['team']=['public_id'=>(string)$team['public_id'],'label'=>(string)$team['name'],'url'=>'/team.php?id='.rawurlencode((string)$team['public_id'])];
    }
    return $out;
}

function workspace_context_object(PDO $pdo,array $viewer,string $type,string $publicId): ?array {
    $type=strtolower(trim($type));$publicId=workspace_context_ref($publicId);if($publicId==='')return null;
    if($type==='annotation'){
        $a=annotation_access($pdo,$publicId,$viewer);if(!$a)return null;
        if((int)$a['user_id']!==(int)$viewer['id']&&function_exists('is_blocked')&&is_blocked($pdo,(int)$viewer['id'],(int)$a['user_id']))return null;
        $out=['type'=>'annotation','public_id'=>$publicId,'label'=>'Annotation','url'=>'/annotation.php?id='.rawurlencode($publicId)];
        if(!empty($a['team_id'])){
            $q=$pdo->prepare("SELECT t.public_id,t.name FROM teams t JOIN team_members tm ON tm.team_id=t.id AND tm.user_id=? WHERE t.id=? LIMIT 1");
            $q->execute([$viewer['id'],$a['team_id']]);if($team=$q->fetch())$out['team']=['public_id'=>(string)$team['public_id'],'label'=>(string)$team['name'],'url'=>'/team.php?id='.rawurlencode((string)$team['public_id'])];
        }
        return $out;
    }
    if($type==='bookmark'&&function_exists('research_agent_workspace_object')){
        $bookmark=research_agent_workspace_object($pdo,$viewer,$publicId,false);if(!$bookmark||($bookmark['object_type']??'')!=='bookmark')return null;
        $out=['type'=>'bookmark','public_id'=>$publicId,'label'=>'Bookmark',
          'research'=>['public_id'=>(string)$bookmark['project_public_id'],'label'=>(string)$bookmark['project_title'],'url'=>'/research-project.php?id='.rawurlencode((string)$bookmark['project_public_id'])]];
        if(!empty($bookmark['team_public_id']))$out['team']=['public_id'=>(string)$bookmark['team_public_id'],'label'=>(string)$bookmark['team_name'],'url'=>'/team.php?id='.rawurlencode((string)$bookmark['team_public_id'])];
        return $out;
    }
    if($type==='document'&&function_exists('research_agent_workspace_object')){
        $document=research_agent_workspace_object($pdo,$viewer,$publicId,false);if(!$document||($document['object_type']??'')!=='document')return null;
        $conversation=trim((string)($document['conversation_public_id']??''));
        $out=['type'=>'document','public_id'=>$publicId,'label'=>'Research document',
          'url'=>$conversation!==''?'/home.php?agent='.rawurlencode($conversation).'&doc='.rawurlencode($publicId):'/research-project.php?id='.rawurlencode((string)$document['project_public_id']),
          'research'=>['public_id'=>(string)$document['project_public_id'],'label'=>(string)$document['project_title'],'url'=>'/research-project.php?id='.rawurlencode((string)$document['project_public_id'])]];
        if(!empty($document['team_public_id']))$out['team']=['public_id'=>(string)$document['team_public_id'],'label'=>(string)$document['team_name'],'url'=>'/team.php?id='.rawurlencode((string)$document['team_public_id'])];
        return $out;
    }
    if($type==='source'){
        $source=source_access($pdo,$publicId,$viewer);if(!$source)return null;
        return ['type'=>'source','public_id'=>$publicId,'label'=>'Source','url'=>'/source.php?id='.rawurlencode($publicId)];
    }
    if($type==='claim'){
        $claim=research_claim_access($pdo,$viewer,$publicId);if(!$claim)return null;
        return ['type'=>'claim','public_id'=>$publicId,'label'=>'Claim','url'=>'/research-claim.php?id='.rawurlencode($publicId),'research'=>['public_id'=>(string)$claim['project_public_id'],'label'=>(string)$claim['project_title'],'url'=>'/research-project.php?id='.rawurlencode((string)$claim['project_public_id'])]];
    }
    if($type==='finding'){
        $finding=research_finding_access($pdo,$viewer,$publicId);if(!$finding)return null;
        return ['type'=>'finding','public_id'=>$publicId,'label'=>'Finding','url'=>'/research-finding.php?id='.rawurlencode($publicId),'research'=>['public_id'=>(string)$finding['project_public_id'],'label'=>(string)$finding['project_title'],'url'=>'/research-project.php?id='.rawurlencode((string)$finding['project_public_id'])]];
    }
    return null;
}

function workspace_context_agent(PDO $pdo,array $viewer,string $conversationPublicId): ?array {
    $conversationPublicId=workspace_context_ref($conversationPublicId);if($conversationPublicId==='')return null;
    $conversation=conversation_access($pdo,$viewer,$conversationPublicId);if(!$conversation||($conversation['conversation_type']??'')!=='agent')return null;
    return ['public_id'=>$conversationPublicId,'label'=>'Agent chat','url'=>'/home.php?agent='.rawurlencode($conversationPublicId)];
}

function workspace_context_resolve(PDO $pdo,array $viewer,array $candidate): array {
    $team=workspace_context_team($pdo,$viewer,(string)($candidate['team_public_id']??''));
    $research=workspace_context_research($pdo,$viewer,(string)($candidate['research_public_id']??''));
    $object=workspace_context_object($pdo,$viewer,(string)($candidate['object_type']??''),(string)($candidate['object_public_id']??''));
    $agent=workspace_context_agent($pdo,$viewer,(string)($candidate['agent_conversation_public_id']??''));

    if($research&&isset($research['team']))$team=$research['team'];
    if($object&&isset($object['research'])){
        $research=$object['research'];
        $resolvedResearch=workspace_context_research($pdo,$viewer,(string)$research['public_id']);
        if($resolvedResearch){$research=$resolvedResearch;if(isset($resolvedResearch['team']))$team=$resolvedResearch['team'];}
    }
    if($object&&isset($object['team']))$team=$object['team'];

    if($research)unset($research['team']);
    if($object){unset($object['research'],$object['team']);}

    return [
      'viewer_public_id'=>(string)$viewer['public_id'],
      'team'=>$team,
      'research'=>$research,
      'object'=>$object,
      'agent'=>$agent,
    ];
}
