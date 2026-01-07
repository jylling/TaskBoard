import fetch from 'node-fetch';

export async function broadcast(board: number, type: string, payload: any) {
    await fetch('http://127.0.0.1:3001/push', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ board, type, payload, t: Date.now() })
    });
}
