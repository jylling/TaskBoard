import { db } from '$lib/db';
import { broadcast } from '$lib/realtime';
import { json } from '@sveltejs/kit';

/*
POST    → createItem
PUT     → moveItem
PATCH   → renameItem
DELETE  → deleteItem
*/


// CREATE ITEM
export async function POST({ request }) {
    const p = await request.json();

    const [[{ pos }]] = await db.query<any[]>(
        'SELECT COALESCE(MAX(position),0)+1 AS pos FROM items WHERE column_id=?',
        [p.column_id]
    );

    const [r] = await db.query<any>(
        `INSERT INTO items (column_id,title,position,created,updated)
     VALUES (?,?,?,NOW(),NOW())`,
        [p.column_id, p.title, pos]
    );

    const id = r.insertId;

    await broadcast(1, 'itemCreated', {
        id,
        column_id: p.column_id,
        title: p.title,
        position: pos,
        client_id: p.client_id ?? null,
        local_id: p.local_id ?? null
    });

    return json({ ok: 1, id, position: pos });
}



// MOVE ITEM
export async function PUT({ request }) {
    const p = await request.json();

    await db.query(
        'UPDATE items SET column_id=?, position=?, updated=NOW() WHERE id=?',
        [p.column_id, p.position, p.id]
    );

    await broadcast(1, 'itemMoved', {
        id: p.id,
        column_id: p.column_id,
        position: p.position
    });

    return json({ ok: 1 });
}



// RENAME ITEM
export async function PATCH({ request }) {
    const p = await request.json();

    await db.query(
        'UPDATE items SET title=?, updated=NOW() WHERE id=?',
        [p.title, p.id]
    );

    await broadcast(1, 'itemRenamed', {
        id: p.id,
        title: p.title
    });

    return json({ ok: 1 });
}



// DELETE ITEM
export async function DELETE({ request }) {
    const p = await request.json();

    await db.query(
        'DELETE FROM items WHERE id=? AND column_id=?',
        [p.id, p.column_id]
    );

    await broadcast(1, 'itemDeleted', {
        id: p.id,
        column_id: p.column_id
    });

    return json({ ok: 1 });
}

