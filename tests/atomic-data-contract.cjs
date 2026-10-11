const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const source=fs.readFileSync(path.join(__dirname,'../includes/class-staycore-rest.php'),'utf8');
const db=fs.readFileSync(path.join(__dirname,'../includes/class-staycore-db.php'),'utf8');
const safety=fs.readFileSync(path.join(__dirname,'../includes/class-staycore-data-safety.php'),'utf8');
const between=(a,b)=>{const i=source.indexOf('public static function '+a+'('),j=source.indexOf('public static function '+b+'(',i+1);assert(i>=0&&j>i,'Missing PHP endpoint '+a);return source.slice(i,j)};
const create=between('create_reservation','update_reservation');
const edit=between('update_reservation','move_unit');
const move=between('move_unit','set_status');
const status=between('set_status','payments');
const payment=between('add_payment','guest_lookup');

for(const [name,body] of [['create',create],['edit',edit],['move',move],['checkout',status]]){
 assert.match(body,/START TRANSACTION/,name+' must begin a transaction');
 assert.match(body,/ROLLBACK/,name+' must rollback failed writes');
 assert.match(body,/COMMIT/,name+' must commit only after all writes');
}
assert.match(create,/snapshot_reservation\(\$id,'after_creation'\)/,'Creation snapshot required before commit');
assert.match(edit,/safeguard\(\$id,'before_reservation_update'\)/,'Edit requires previous revision');
assert.doesNotMatch(edit,/\$wpdb->delete\(\$t\['reservation_units'\],\['reservation_id'=>\$id\]\)/,'No destructive assignment table wipe');
assert.match(edit,/array_diff\(\$unit_ids,\$old_ids\)/,'Only add new assignments');
assert.match(edit,/array_diff\(\$old_ids,\$unit_ids\)/,'Only remove absent assignments');
assert.match(move,/safeguard\(\$id,'before_unit_move'\)/,'Move requires previous revision');
assert.match(status,/safeguard\(\$id,'before_status_change'\)/,'Checkout requires previous revision');
assert.match(payment,/safeguard\(\$id,'before_payment'\)/,'Captured payments require previous revision');
for(const marker of ['before_group_guest_added','before_group_guest_updated','before_group_guest_cleared','before_group_guest_status','before_self_checkin']){
 assert(source.includes("safeguard($id,'"+marker+"')"),'Unprotected write '+marker);
}
assert.match(db,/CREATE TABLE \{\$t\['revisions'\]\}/,'Revision table must have schema');
assert.match(safety,/hash\('sha256', \$payload\)/,'Revision integrity checksum');
assert.match(safety,/wp_mail\(/,'Database count decrease must alert');
console.log('PASS: Atomic booking write path, fail-closed snapshot and migration safety contracts');
