<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
require_once __DIR__.'/app/public-discovery.php';
require_once __DIR__.'/app/annotation-ui.php';

$u=require_user($pdo);$error='';$success='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    require_csrf();$action=(string)($_POST['action']??'');
    if($action==='create_collection'){
        $title=trim((string)($_POST['title']??''));
        if($title==='')$error='Collection title is required.';
        else{
            $visibility=in_array($_POST['visibility']??'private',['private','public'],true)?$_POST['visibility']:'private';
            $pdo->prepare('INSERT INTO collections(public_id,owner_user_id,title,description,visibility) VALUES(?,?,?,?,?)')
                ->execute([ulid_like(),$u['id'],$title,trim((string)($_POST['description']??''))?:null,$visibility]);
            $success='Collection created.';
        }
    }
    if($action==='add_to_collection'){
        $collection=(string)($_POST['collection']??'');$annotation=(string)($_POST['annotation']??'');
        $q=$pdo->prepare('SELECT id FROM collections WHERE public_id=? AND owner_user_id=?');$q->execute([$collection,$u['id']]);
        $cid=(int)($q->fetchColumn()?:0);$access=annotation_access($pdo,$annotation,$u);$aid=(int)($access['id']??0);
        if($cid&&$aid){$pdo->prepare('INSERT IGNORE INTO collection_items(collection_id,annotation_id,added_by_user_id) VALUES(?,?,?)')->execute([$cid,$aid,$u['id']]);$success='Added to collection.';}
        else $error='Unable to add that annotation.';
    }
    if($action==='remove_saved'){
        $annotation=(string)($_POST['annotation']??'');
        $q=$pdo->prepare('DELETE sa FROM saved_annotations sa JOIN annotations a ON a.id=sa.annotation_id WHERE sa.user_id=? AND a.public_id=?');
        $q->execute([$u['id'],$annotation]);$success='Removed from Saved.';
    }
}
$q=$pdo->prepare("SELECT a.public_id FROM saved_annotations sa JOIN annotations a ON a.id=sa.annotation_id WHERE sa.user_id=? ORDER BY sa.created_at DESC");
$q->execute([$u['id']]);$saved=[];
foreach($q->fetchAll(PDO::FETCH_COLUMN) as $aid){$row=public_discovery_annotation($pdo,(string)$aid,$u);if($row)$saved[]=$row;}
$q=$pdo->prepare("SELECT c.public_id,c.title,c.description,c.visibility,c.updated_at,
 (SELECT COUNT(*) FROM collection_items ci WHERE ci.collection_id=c.id) item_count,
 COALESCE((SELECT MAX(ci2.created_at) FROM collection_items ci2 WHERE ci2.collection_id=c.id),c.updated_at) recent_at
 FROM collections c WHERE c.owner_user_id=? ORDER BY recent_at DESC,c.id DESC");
$q->execute([$u['id']]);$collections=$q->fetchAll();
$selected=null;$items=[];
if(!empty($_GET['collection'])){
    $q=$pdo->prepare('SELECT * FROM collections WHERE public_id=? AND owner_user_id=?');$q->execute([(string)$_GET['collection'],$u['id']]);$selected=$q->fetch();
    if($selected){$q=$pdo->prepare("SELECT a.public_id FROM collection_items ci JOIN annotations a ON a.id=ci.annotation_id WHERE ci.collection_id=? ORDER BY ci.created_at DESC");$q->execute([$selected['id']]);foreach($q->fetchAll(PDO::FETCH_COLUMN) as $aid){$row=public_discovery_annotation($pdo,(string)$aid,$u);if($row)$items[]=$row;}}
}
$rows=$selected?$items:$saved;
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Saved · Annotated</title><link rel="stylesheet" href="/assets/css/app.css"></head>
<body data-workspace-user="<?=h((string)$u['public_id'])?>" data-workspace-surface="saved">
<main class="savedLibraryCanvas">
  <section class="libraryCanvasHero">
    <div><span class="eyebrow">LIBRARY</span><h1><?=$selected?h($selected['title']):'Saved'?></h1><p><?=$selected&&$selected['description']?h($selected['description']):'Your saved annotations and curated collections, organized like a working research library.'?></p></div>
    <details class="libraryAddMenu"<?= $error!==''?' open':'' ?>><summary class="button">ADD COLLECTION</summary><div class="libraryCreatePopover"><span class="eyebrow">NEW COLLECTION</span><h2>Create collection</h2><?php if($error):?><div class="error"><?=h($error)?></div><?php endif?><form method="post" class="stack"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="create_collection"><label>Title<input name="title" required value="<?=h((string)($_POST['title']??''))?>"></label><label>Description<textarea name="description" rows="3"><?=h((string)($_POST['description']??''))?></textarea></label><label>Visibility<select name="visibility"><option value="private">Private</option><option value="public">Public</option></select></label><button>Create Collection</button></form></div></details>
  </section>
  <?php if($success):?><div class="success libraryCanvasMessage"><?=h($success)?></div><?php endif?>
  <section class="libraryCanvasToolbar"><nav><a class="<?=$selected?'':'active'?>" href="/saved.php">All Saved <span><?=h((string)count($saved))?></span></a><?php if($selected):?><a class="active" href="/saved.php?collection=<?=h($selected['public_id'])?>"><?=h($selected['title'])?> <span><?=h((string)count($items))?></span></a><?php endif?></nav><span class="libraryToolbarMeta"><?=h((string)count($collections))?> collections</span></section>
  <?php if(!$selected&&$collections):?><section class="savedCollectionGrid" aria-label="Saved collections"><?php foreach($collections as $collection):?><a class="savedCollectionCard" href="/saved.php?collection=<?=h($collection['public_id'])?>"><div class="libraryFolderGlyph" aria-hidden="true"></div><div class="savedCollectionCopy"><span class="savedCollectionVisibility"><?=h(ucfirst((string)$collection['visibility']))?></span><h2><?=h($collection['title'])?></h2><?php if(trim((string)$collection['description'])!==''):?><p><?=h($collection['description'])?></p><?php endif?></div><div class="savedCollectionMeta"><strong><?=h((string)$collection['item_count'])?></strong><span>items</span></div></a><?php endforeach?></section><?php endif?>
  <section class="libraryContentHeader"><div><span class="eyebrow"><?=$selected?'COLLECTION':'ANNOTATIONS'?></span><h2><?=$selected?'Collection items':'Saved annotations'?></h2></div><?php if($selected):?><a class="button secondary" href="/saved.php">← All Saved</a><?php endif?></section>
  <?php if(!$rows):?><div class="libraryCanvasEmpty"><div class="libraryFolderGlyph" aria-hidden="true"></div><h2>Nothing here yet</h2><p>Save annotations or add them to a collection and they will appear here.</p></div><?php else:?><section class="savedAnnotationGrid"><?php foreach($rows as $a):?><div class="savedAnnotationItem"><?=annotation_ui_card($a,$u)?><?php if(!$selected&&$collections):?><form method="post" class="savedCollectionAction"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="add_to_collection"><input type="hidden" name="annotation" value="<?=h($a['public_id'])?>"><select name="collection" aria-label="Collection"><?php foreach($collections as $collection):?><option value="<?=h($collection['public_id'])?>"><?=h($collection['title'])?></option><?php endforeach?></select><button class="button secondary">Add to collection</button></form><?php endif?></div><?php endforeach?></section><?php endif?>
</main>
<?=annotation_ui_scripts($u)?>
</body></html>