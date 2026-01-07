<?php
header('Content-Type: application/json');
require __DIR__ . "/connect.php";


function dbg($label, $data = null) {
    $t = date("H:i:s");
    $dump = $data !== null ? json_encode($data, JSON_PRETTY_PRINT) : "";
    file_put_contents(__DIR__ . "debug.log", "[$t] $label $dump\n", FILE_APPEND);
}

$db = new PDO("mysql:host=$server;dbname=$db_navn",$brugernavn,$password);
//$db = new PDO("mysql:host=jylling.dk.mysql;dbname=jylling_dk","jylling_dk","svanemosen41!");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* JSON helpers */
function post(){ return json_decode(file_get_contents("php://input"),true); }
function out($x){ echo json_encode($x); exit; }

/* REALTIME BUS */
/* function broadcast($type,$payload){
  $msg=json_encode(["type"=>$type,"payload"=>$payload,"t"=>time()]);
  file_put_contents("events.txt",$msg."\n",FILE_APPEND);
}
 */
function broadcast($board, $type, $payload) {

  $msg = json_encode([
    "type" => $type,
    "payload" => $payload,
    "t" => time()
  ]);

  // push til VPS
  $ch = curl_init("https://13.51.150.249/push.php");
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode([
      "board" => $board,
      "msg"   => $msg
    ]),
    CURLOPT_HTTPHEADER => ["Content-Type: application/json"],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 1
  ]);
  curl_exec($ch);
  curl_close($ch);
}




$a=$_GET['action'] ?? "";


/* GET BOARD */
if($a==="getBoard"){
  $cols=$db->query("SELECT * FROM columns WHERE board_id=1 ORDER BY position")->fetchAll(PDO::FETCH_ASSOC);
  foreach($cols as &$c){
    $q=$db->prepare("SELECT * FROM items WHERE column_id=? ORDER BY position");
    $q->execute([$c['id']]);
    $c['items']=$q->fetchAll(PDO::FETCH_ASSOC);
  }
  out($cols);
}

/* CREATE ITEM */
if($a==="createItem"){
  $p=post();
  $pos=$db->query("SELECT COALESCE(MAX(position),0)+1 FROM items WHERE column_id=".$p['column_id'])->fetchColumn();
  $db->prepare("INSERT INTO items(column_id,title,position,created,updated)
      VALUES(?,?,?,NOW(),NOW())")->execute([$p['column_id'],$p['title'],$pos]);

  $id = $db->lastInsertId();

broadcast(1,"itemCreated",[
  "id"=>$id,
  "column_id"=>$p['column_id'],
  "title"=>$p['title'],
  "position"=>$pos,
  "client_id"=>$p['client_id'] ?? null,
  "local_id"=>$p['local_id'] ?? null
]);
    
 out(["ok"=>1, "id"=>$id, "position"=>$pos]);
}

/* CREATE COLUMN */
if($a==="createColumn"){
  $p=post();
  
  $pos = $db->query("SELECT COALESCE(MAX(CAST(position AS UNSIGNED)),0) + 1 FROM columns WHERE board_id = 1")->fetchColumn();
    $db->prepare("INSERT INTO columns (board_id, name, position) VALUES (?, ?, ?)")->execute([1, $p['name'], $pos]);
  $id = $db->lastInsertId();

broadcast(1, "columnCreated", [
  "id" => $id,
  "name" => $p['name'],
  "position" => $pos,
  "client_id" => $p['client_id'] ?? null,
  "local_id" => $p['local_id'] ?? null
]);
    
 out(["ok"=>1, "id"=>$id, "position"=>$pos]);
}

/* MOVE ITEM */
if ($a === "moveItem") {
  $p = post();
  
  $db->prepare("UPDATE items SET column_id=?, position=?, updated=NOW() WHERE id=?")
     ->execute([$p['column_id'],$p['position'],$p['id']]);

  broadcast(1, "itemMoved", [
    "id"=>$p['id'],
    "column_id"=>$p['column_id'],
    "position"=>$p['position']
  ]);

  out(["ok"=>1]);
}

/* MOVE COLUMN */
if ($a === "moveColumn") {
  $p = post();

  $db->beginTransaction();
  $stmt = $db->prepare("UPDATE columns SET position=? WHERE id=?");

  foreach ($p['positions'] as $pos) {
    $stmt->execute([(int)$pos['position'], (int)$pos['id']]);
    broadcast(1, "columnMoved", ["id"=>$pos['id'], "position"=>$pos['position']]);
  }

  $db->commit();
  out(["ok"=>1]);
}





/* RENAME */
if($a==="renameItem"){
  $p=post();

  $db->prepare("UPDATE items SET title=?, updated=NOW() WHERE id=?")
     ->execute([$p['title'],$p['id']]);

  broadcast(1, "itemRenamed",[
    "id"=>$p['id'],
    "title"=>$p['title']
  ]);

  out(["ok"=>1]);
}

/* DELETE */
if($a==="deleteItem"){
  $p=post();
  $db->prepare("DELETE FROM items WHERE id=? and column_id=? ")->execute([$p['id'], $p['column_id']]);
 
   broadcast(1, "itemDeleted", [
    "id"=>$p['id'],
    "column_id"=>$p['column_id']
  ]);
  out(["ok"=>1]);
}


if ($a === "deleteColumn") {
  $p = post();
  dbg("Overført parameter", $p);
  $cid = (int)$p["column_id"];
  dbg("$cid", $cid);

  $db->beginTransaction();

  // 1) slet alle kort
  $db->prepare("DELETE FROM items WHERE column_id = ?")->execute([$cid]);

  // 2) slet kolonnen
  $db->prepare("DELETE FROM columns WHERE id = ?")->execute([$cid]);

  // 3) genberegn positions
  $cols = $db->query("SELECT id FROM columns WHERE board_id = 1 ORDER BY position")->fetchAll(PDO::FETCH_COLUMN);
  $pos = 1;
  $stmt = $db->prepare("UPDATE columns SET position=? WHERE id=?");
  foreach ($cols as $id) {
    $stmt->execute([$pos++, $id]);
  }

  $db->commit();

  broadcast(1, "columnDeleted", ["id"=>$cid]);
  out(["ok"=>1]);
}

out(["error"=>"unknown"]);
?>