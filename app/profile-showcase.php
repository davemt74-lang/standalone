<?php
declare(strict_types=1);

function profile_showcase_ready(PDO $pdo): bool {
    try{return installer_table_exists($pdo,'profile_pins');}catch(Throwable $e){return false;}
}
function profile_showcase_preferences(PDO $pdo,int $userId): array {
    $defaults=['profile_show_research'=>1,'profile_show_collections'=>1,'profile_show_about'=>1];
    if($userId<1)return $defaults;
    try{$q=$pdo->prepare('SELECT profile_show_research,profile_show_collections,profile_show_about FROM user_preferences WHERE user_id=? LIMIT 1');$q->execute([$userId]);$row=$q->fetch();return $row?array_merge($defaults,$row):$defaults;}catch(Throwable $e){return $defaults;}
}
function profile_showcase_public_collections(PDO $pdo,int $userId,int $limit=30): array {
    $limit=max(1,min(60,$limit));
    $q=$pdo->prepare("SELECT c.public_id,c.title,c.description,c.updated_at,c.created_at,
      (SELECT COUNT(*) FROM collection_items ci JOIN annotations a ON a.id=ci.annotation_id WHERE ci.collection_id=c.id AND a.visibility='public' AND a.status='published') item_count,
      (SELECT MAX(ci2.created_at) FROM collection_items ci2 JOIN annotations a2 ON a2.id=ci2.annotation_id WHERE ci2.collection_id=c.id AND a2.visibility='public' AND a2.status='published') recent_item_at
      FROM collections c WHERE c.owner_user_id=? AND c.visibility='public'
      ORDER BY COALESCE((SELECT MAX(ci3.created_at) FROM collection_items ci3 WHERE ci3.collection_id=c.id),c.updated_at) DESC,c.id DESC LIMIT ".$limit);
    $q->execute([$userId]);return $q->fetchAll()?:[];
}
function profile_showcase_people(PDO $pdo,int $profileUserId,string $type,?array $viewer,int $limit=100): array {
    $limit=max(1,min(200,$limit));$viewerId=(int)($viewer['id']??0);
    if($type==='following'){$join='JOIN users u ON u.id=f.followed_user_id';$where='f.follower_user_id=?';}
    else{$join='JOIN users u ON u.id=f.follower_user_id';$where='f.followed_user_id=?';}
    $block=$viewerId?" AND NOT EXISTS(SELECT 1 FROM blocks b WHERE (b.blocker_user_id=$viewerId AND b.blocked_user_id=u.id) OR (b.blocker_user_id=u.id AND b.blocked_user_id=$viewerId))":'';
    $sql="SELECT u.public_id,u.username,u.display_name,u.bio,u.profile_image_url,f.created_at
      FROM follows f $join LEFT JOIN user_preferences p ON p.user_id=u.id
      WHERE $where AND u.status='active' AND COALESCE(p.profile_visibility,'public')='public'".$block."
      ORDER BY f.created_at DESC LIMIT ".$limit;
    $q=$pdo->prepare($sql);$q->execute([$profileUserId]);return $q->fetchAll()?:[];
}
function profile_showcase_object_owned_public(PDO $pdo,int $userId,string $type,string $publicId): bool {
    if($type==='annotation'){$q=$pdo->prepare("SELECT 1 FROM annotations WHERE public_id=? AND user_id=? AND visibility='public' AND status='published' LIMIT 1");}
    elseif($type==='research_report'){$q=$pdo->prepare("SELECT 1 FROM research_reports WHERE public_id=? AND created_by_user_id=? AND visibility='public' AND status='published' LIMIT 1");}
    elseif($type==='collection'){$q=$pdo->prepare("SELECT 1 FROM collections WHERE public_id=? AND owner_user_id=? AND visibility='public' LIMIT 1");}
    else return false;
    $q->execute([$publicId,$userId]);return (bool)$q->fetchColumn();
}
function profile_showcase_normalize_pins(PDO $pdo,int $userId): void {
    if(!profile_showcase_ready($pdo))return;$q=$pdo->prepare('SELECT id FROM profile_pins WHERE user_id=? ORDER BY position,id');$q->execute([$userId]);$ids=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
    $pdo->beginTransaction();try{$temp=100;foreach($ids as $id){$pdo->prepare('UPDATE profile_pins SET position=? WHERE id=?')->execute([$temp++,$id]);}$pos=1;foreach($ids as $id){$pdo->prepare('UPDATE profile_pins SET position=? WHERE id=?')->execute([$pos++,$id]);}$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function profile_showcase_prune_pins(PDO $pdo,int $userId): void {
    if(!profile_showcase_ready($pdo)||$userId<1)return;$q=$pdo->prepare('SELECT id,object_type,object_public_id FROM profile_pins WHERE user_id=? ORDER BY position,id');$q->execute([$userId]);$removed=false;
    foreach($q->fetchAll() as $row)if(!profile_showcase_object_owned_public($pdo,$userId,(string)$row['object_type'],(string)$row['object_public_id'])){$pdo->prepare('DELETE FROM profile_pins WHERE id=?')->execute([(int)$row['id']]);$removed=true;}
    if($removed)profile_showcase_normalize_pins($pdo,$userId);
}
function profile_showcase_pin_set(PDO $pdo,array $viewer,string $type,string $publicId,bool $pinned): void {
    if(!profile_showcase_ready($pdo))throw new RuntimeException('Profile showcase requires the latest database upgrade.');
    $uid=(int)$viewer['id'];$type=trim($type);$publicId=trim($publicId);
    if(!in_array($type,['annotation','research_report','collection'],true)||$publicId==='')throw new InvalidArgumentException('Unsupported profile item.');
    if(!profile_showcase_object_owned_public($pdo,$uid,$type,$publicId))throw new RuntimeException('Only your own public content can be pinned.');
    profile_showcase_prune_pins($pdo,$uid);
    if(!$pinned){$pdo->prepare('DELETE FROM profile_pins WHERE user_id=? AND object_type=? AND object_public_id=?')->execute([$uid,$type,$publicId]);profile_showcase_normalize_pins($pdo,$uid);return;}
    $q=$pdo->prepare('SELECT id FROM profile_pins WHERE user_id=? AND object_type=? AND object_public_id=? LIMIT 1');$q->execute([$uid,$type,$publicId]);if($q->fetchColumn())return;
    $q=$pdo->prepare('SELECT COUNT(*) FROM profile_pins WHERE user_id=?');$q->execute([$uid]);if((int)$q->fetchColumn()>=3)throw new RuntimeException('You can pin up to 3 public profile items.');
    $q=$pdo->prepare('SELECT COALESCE(MAX(position),0)+1 FROM profile_pins WHERE user_id=?');$q->execute([$uid]);$position=(int)$q->fetchColumn();
    $pdo->prepare('INSERT INTO profile_pins(user_id,object_type,object_public_id,position) VALUES(?,?,?,?)')->execute([$uid,$type,$publicId,$position]);
}
function profile_showcase_pins(PDO $pdo,array $profile,?array $viewer): array {
    if(!profile_showcase_ready($pdo))return [];if($viewer&&(int)($viewer['id']??0)===(int)$profile['id'])profile_showcase_prune_pins($pdo,(int)$profile['id']);$q=$pdo->prepare('SELECT object_type,object_public_id,position FROM profile_pins WHERE user_id=? ORDER BY position,id LIMIT 3');$q->execute([(int)$profile['id']]);$out=[];
    foreach($q->fetchAll() as $pin){$type=(string)$pin['object_type'];$id=(string)$pin['object_public_id'];$item=null;
      if($type==='annotation')$item=public_discovery_annotation($pdo,$id,$viewer);
      elseif($type==='research_report'){foreach((array)($profile['reports']??[]) as $r)if((string)$r['public_id']===$id){$item=$r;break;}}
      elseif($type==='collection'){foreach(profile_showcase_public_collections($pdo,(int)$profile['id'],60) as $c)if((string)$c['public_id']===$id){$item=$c;break;}}
      if($item)$out[]=['type'=>$type,'public_id'=>$id,'position'=>(int)$pin['position'],'item'=>$item];
    }return $out;
}
function profile_showcase_activity(array $profile,array $collections): array {
    $rows=[];foreach((array)($profile['annotations']??[]) as $a)$rows[]=['type'=>'annotation','at'=>(string)($a['published_at']??''),'item'=>$a];
    foreach((array)($profile['reports']??[]) as $r)$rows[]=['type'=>'research_report','at'=>(string)($r['published_at']??''),'item'=>$r];
    foreach($collections as $c)$rows[]=['type'=>'collection','at'=>(string)($c['recent_item_at']?:$c['updated_at']?:$c['created_at']),'item'=>$c];
    usort($rows,static fn($a,$b)=>strcmp((string)$b['at'],(string)$a['at']));return array_slice($rows,0,50);
}
