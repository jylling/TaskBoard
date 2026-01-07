import { db } from '$lib/db';
import { json } from '@sveltejs/kit';

export async function GET() {
    const [cols] = await db.query('SELECT * FROM columns WHERE board_id=1 ORDER BY position');

    for (const c of cols as any[]) {
        const [items] = await db.query('SELECT * FROM items WHERE column_id=? ORDER BY position', [c.id]);
        c.items = items;
    }

    return json(cols);
}
