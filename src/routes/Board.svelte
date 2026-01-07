<script lang="ts">
	import kebab from '$lib/assets/kebab-menu-svgrepo-com.svg';
	import { t } from '$lib/stores/lang';
	let addingTo: number | null = null;
	let addingNewCol: number | null = null;
	let newTitle = '';
	let newColTitle = '';
	let dragLock = false;
	let flipKey = 0;

	let selectedCard: any = null;
	const CLIENT_ID = crypto.randomUUID();

	const LOCAL_FLIP = 200;
	const REMOTE_FLIP = 600;

	let currentFlip = LOCAL_FLIP;

	// This is done in a single file for clarity. A more factored version here: https://svelte.dev/repl/288f827275db4054b23c437a572234f6?version=3.38.2
	import { flip } from 'svelte/animate';
	import { dndzone } from 'svelte-dnd-action';

	import { fly, fade } from 'svelte/transition';
	import { quintOut } from 'svelte/easing';
	import { tick } from 'svelte';

	//const es = new EventSource('http://localhost/boards/sse.php');
	//  const es = new EventSource('sse.php');  <== før Amazon
	const BOARD_ID = 1; // senere dynamisk
	const es = new EventSource(`https://13.51.150.249/sse.php?board=${BOARD_ID}`);

	function insertRemoteCard(p: any) {
		const columnId = Number(p.column_id);
		const id = Number(p.id);
		const title = p.title;

		if (!Number.isFinite(columnId) || !Number.isFinite(id) || !title) return;

		const colIdx = columnItems.findIndex((c) => Number(c.id) === columnId);
		if (colIdx === -1) return;

		const items = columnItems[colIdx].items ?? [];

		// Undgå duplicate insert (kan ske ved replays / race)
		if (items.some((it: any) => Number(it.id) === id)) return;

		// Position er 1-baseret i din DB
		const wanted = Number(p.position ?? items.length + 1);
		const pos = Math.max(0, Math.min(wanted - 1, items.length));

		const newItem = { id, title };

		const newItems = [...items.slice(0, pos), newItem, ...items.slice(pos)];

		// Immutable update så dndzone ikke går i stykker
		columnItems = columnItems.map((c, i) => (i === colIdx ? { ...c, items: newItems } : c));
	}

	es.onmessage = (e) => {
		//if (dragLock) return;
		const msg = JSON.parse(e.data);
		console.log('SSE:', msg);
		if (msg.type === 'itemCreated') {
			const p = msg.payload;

			// EGEN klient → resolve temp-kort
			if (p.client_id === CLIENT_ID && p.local_id) {
				columnItems = columnItems.map((c) => ({
					...c,
					items: c.items.map((it: any) =>
						it.id === p.local_id ? { ...it, id: Number(p.id), _pending: false } : it
					)
				}));
				return;
			}

			// andre klienter
			if (!p?.id || !p?.column_id || !p?.title) return;
			if (dragLock) return;
			insertRemoteCard(p);
		}

		if (msg.type === 'columnCreated') {
			const p = msg.payload;

			// egen browser → resolve temp kolonne
			if (p.client_id === CLIENT_ID && p.local_id) {
				columnItems = columnItems.map((c) =>
					c.id === p.local_id ? { ...c, id: Number(p.id), _pending: false } : c
				);
				return;
			}

			// andre browsere
			const pos = Number(p.position) - 1;
			columnItems = [
				...columnItems.slice(0, pos),
				{ id: Number(p.id), name: p.name, items: [] },
				...columnItems.slice(pos)
			];
		}

		if (msg.type === 'itemMoved') {
			applyRemoteMove(msg.payload);
		}

		if (msg.type === 'columnMoved') {
			const idx = columnItems.findIndex((c) => c.id === msg.payload.id);
			if (idx === -1) return;

			const col = columnItems.splice(idx, 1)[0];
			columnItems.splice(msg.payload.position - 1, 0, col);
			columnItems = [...columnItems];
			flipKey++; // 👈 VIGTIG
		}

		if (msg.type === 'itemDeleted') {
			const id = Number(msg.payload.id);
			const columnId = Number(msg.payload.column_id);

			for (const col of columnItems) {
				if (Number(col.id) === columnId) {
					col.items = col.items.filter((it) => Number(it.id) !== id);
					break;
				}
			}

			columnItems = [...columnItems];
		}

		if (msg.type === 'columnDeleted') {
			const id = Number(msg.payload.id);
			columnItems = columnItems.filter((c) => Number(c.id) !== id);
			setTimeout(() => (currentFlip = LOCAL_FLIP), REMOTE_FLIP + 50);
		}
	};

	export let columnItems: any[] = [];
	const flipDurationMs = 200;

	function handleDndConsiderColumns(e: any) {
		columnItems = e.detail.items;
		flipKey++; // 👈 force nested FLIP
		dragLock = true;
	}
	async function handleDndFinalizeColumns(e: any) {
		columnItems = e.detail.items;
		dragLock = false;

		const positions = columnItems.map((c, i) => ({ id: c.id, position: i + 1 }));

		const r = await fetch('api.php?action=moveColumn', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({ positions })
		});

		const txt = await r.text();
		console.log('moveColumn status:', r.status, txt);
	}

	function handleDndConsiderCards(cid: any, e: any) {
		dragLock = true;
		const colIdx = columnItems.findIndex((c) => c.id === cid);
		columnItems[colIdx].items = e.detail.items;
		columnItems = [...columnItems];
	}

	async function handleDndFinalizeCards(cid: number, e: any) {
		// ✅ "items" er nu defineret i funktionen
		dragLock = false;
		const items = e.detail.items as Array<{ id: number; name?: string; title?: string }>;

		// Opdater UI (optimistic)
		const colIdx = columnItems.findIndex((c) => c.id === cid);
		if (colIdx === -1) return;

		columnItems[colIdx].items = items;
		columnItems = [...columnItems];

		// ✅ Skriv til DB: opdater position for hvert item i kolonnen
		// (Du kan senere optimere til 1 batch-kald)
		for (let i = 0; i < items.length; i++) {
			//await fetch('./api.php?action=moveItem', {
			await fetch('api.php?action=moveItem', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({
					id: items[i].id,
					column_id: cid,
					position: i + 1
				})
			});
		}
	}

	function applyRemoteMove(p: { id: number; column_id: number; position: number }) {
		const from = columnItems.find((c) => c.items.find((i) => i.id === p.id));
		if (!from) return;

		const item = from.items.find((i) => i.id === p.id);
		from.items = from.items.filter((i) => i.id !== p.id);

		const to = columnItems.find((c) => c.id === p.column_id);
		to.items.splice(p.position - 1, 0, item);

		columnItems = [...columnItems];
	}

	function handleClick(e: any) {
		alert('dragabble elements are still clickable :)');
	}

	async function handleDeleteCard(columnId: number, itemId: number) {
		// Optimistic UI update
		columnItems = columnItems.map((col) =>
			Number(col.id) === Number(columnId)
				? { ...col, items: col.items.filter((it: any) => Number(it.id) !== Number(itemId)) }
				: col
		);
		// Server
		await fetch('api.php?action=deleteItem', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({
				id: itemId,
				column_id: columnId
			})
		});
	}

	async function handleDeleteColumn(columnId: number) {
		console.log('columnId: ', columnId);
		// 1️⃣ Optimistic UI
		columnItems = columnItems.filter((c) => Number(c.id) !== Number(columnId));

		// 2️⃣ Server
		await fetch('api.php?action=deleteColumn', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({ column_id: columnId })
		});
	}

	function createCard(columnId: number) {
		if (!newTitle.trim()) return;

		const localId = 'tmp-' + crypto.randomUUID();

		const newCard = { id: localId, title: newTitle.trim(), _pending: true };

		const colIdx = columnItems.findIndex((c) => c.id === columnId);
		columnItems = columnItems.map((c, i) =>
			i === colIdx ? { ...c, items: [...c.items, newCard] } : c
		);

		fetch('api.php?action=createItem', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({
				column_id: columnId,
				title: newCard.title,
				client_id: CLIENT_ID,
				local_id: localId
			})
		});
		newTitle = '';
		addingTo = null;
	}

	function createCol(columnId: number) {
		if (!newColTitle.trim()) return;

		const localId = 'tmp-col-' + crypto.randomUUID();

		const tempCol = { id: localId, name: newColTitle.trim(), items: [], _pending: true };

		columnItems = [...columnItems, tempCol];

		fetch('api.php?action=createColumn', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({
				name: tempCol.name,
				local_id: localId,
				client_id: CLIENT_ID
			})
		});
		newColTitle = '';
		addingNewCol = null;
	}

	let emptyDragImage: HTMLImageElement;

	if (typeof window !== 'undefined') {
		emptyDragImage = new Image();
		emptyDragImage.src =
			'data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"></svg>';
	}

	function startAdd(cid: number) {
		addingTo = cid;
		newTitle = '';
		tick(); // sørger for DOM er klar
	}
</script>

<div class="light">
	<div
		class="board"
		use:dndzone={{
			items: columnItems,
			flipDurationMs,
			type: 'columns',
			dropTargetStyle: {}
		}}
		on:consider={handleDndConsiderColumns}
		on:finalize={handleDndFinalizeColumns}
	>
		{#each columnItems as column (column.id)}
			<div
				class="column relative overflow-hidden rounded-2xl bg-gray-100 shadow-2xl"
				animate:flip={{ duration: flipDurationMs }}
			>
				<div class="column-title">
					{column.name}
					<button class="col-botton" popovertarget={'colmenu-' + column.id}>
						<img src={kebab} alt="" width="20" height="20" />
					</button>

					<div class="colmenu" id={'colmenu-' + column.id} popover>
						<a href="#">{$t.RenameColumn}</a>
						<a
							href="#"
							on:click={() => {
								handleDeleteColumn(column.id);
								document.getElementById('colmenu-' + column.id)?.hidePopover();
							}}
						>
							{$t.deleteColumn}
						</a>
					</div>
				</div>
				<div
					class="column-content"
					use:dndzone={{
						items: column.items,
						flipDurationMs,
						dropTargetStyle: {
							border: '1px dashed #gray',
							borderRadius: '8px',
							marginBottom: '10px'
						}
					}}
					on:consider={(e) => handleDndConsiderCards(column.id, e)}
					on:finalize={(e) => handleDndFinalizeCards(column.id, e)}
				>
					{#key flipKey}
						{#each column.items as item (item.id)}
							<div
								class="card"
								animate:flip={{ duration: flipDurationMs }}
								on:click={() => (selectedCard = { ...item, column_id: column.id })}
							>
								<button
									class="card-delete"
									on:click|stopPropagation={() => handleDeleteCard(column.id, item.id)}
									aria-label="Delete card"
								>
									✕
								</button>

								<div class="card-header">
									<h3 class="card-title">{item.title}</h3>
								</div>

								<p class="card-text">Her kan du placere en beskrivende tekst for kortet.</p>

								<!-- 		{#each column.items as item (item.id)} -->
								<div class="card-footer">
									<span class="card-tag bg-green-400">Task</span>
								</div>
							</div>
						{/each}
					{/key}
				</div>
				{#if addingTo === column.id}
					<input
						class="new-card-input"
						bind:value={newTitle}
						placeholder="Indtast kortets overskrift..."
						autofocus
						on:keydown={(e) => {
							if (e.key === 'Enter') createCard(column.id);
							if (e.key === 'Escape') addingTo = null;
						}}
					/>
				{/if}
				<div class="add-card" on:click={() => startAdd(column.id)}>+ {$t.addTask}</div>
			</div>
		{/each}
		{#if addingNewCol}
			<div class="column relative overflow-hidden rounded-2xl bg-gray-100 shadow-2xl">
				<div class="column-title">
					<input
						class="w-full bg-transparent font-semibold outline-none"
						bind:value={newColTitle}
						placeholder="Indtast overskrift..."
						autofocus
						on:keydown={(e) => {
							if (e.key === 'Enter') createCol((columnItems[columnItems.length - 1]?.id ?? 0) + 2);
							if (e.key === 'Escape') addingNewCol = null;
						}}
					/>
				</div>

				<!-- ⚠️ vigtig: spacer der giver korrekt flex-geometri -->
				<div class="column-content"></div>

				<div class="add-card invisible">+</div>
			</div>
		{:else}
			<div class="column pt-4">
				<div class="column-title" on:click={() => (addingNewCol = true)}>+ {$t.addColumn}</div>
			</div>
		{/if}
	</div>
</div>

{#if selectedCard}
	<!-- MODAL -->
	<!-- <div class="modal-backdrop" on:click={() => (selectedCard = null)}>
		<div class="modal" on:click|stopPropagation>
			<h2>Rediger kort</h2>

			<input class="modal-input" bind:value={selectedCard.title} placeholder="Titel" />

			<textarea class="modal-textarea" placeholder="Beskrivelse..."></textarea>

			<div class="modal-actions">
				<button on:click={() => saveCard(selectedCard)}>Gem</button>
				<button on:click={() => (selectedCard = null)}>Annuller</button>
			</div>
		</div>
	</div> -->

	<div class="modal-backdrop" on:click={() => (selectedCard = null)}>
		<div
			class="grid min-h-[600px] w-[1000px] max-w-[95vw] grid-cols-[1fr_360px] overflow-hidden rounded-xl bg-white shadow-2xl"
			on:click|stopPropagation
		>
			<!-- MAIN -->
			<div class="p-8">
				<!-- Header -->
				<div class="mb-6 flex gap-3">
					<input class="modal-input" bind:value={selectedCard.title} placeholder="Titel" />
					<button class="text-xl text-gray-400 hover:text-black">✕</button>
				</div>

				<!-- Action bar -->
				<div class="mb-8 flex flex-wrap gap-2">
					<button class="btn">+ Add</button>
					<button class="btn">🕒 Dates</button>
					<button class="btn">☑ Checklist</button>
					<button class="btn">👥 Members</button>
					<button class="btn">📎 Attachment</button>
				</div>

				<!-- Labels -->
				<div class="flex">
					<div class="flex w-40 flex-wrap">Due dato</div>
					<div class="mb-2 flex text-gray-600">Labels</div>
				</div>
				<div class="flex">
					<div class="flex w-40 flex-wrap">
						<div class="flex h-8 items-center bg-gray-100 px-2 text-sm">14/11-1966 - 13:00</div>
					</div>
					<div class="mb-2 flex gap-2">
						<span class="rounded-md bg-green-400 px-3 py-1 text-sm text-white">Arbejde</span>
						<button class="rounded-md border px-2">+</button>
					</div>
				</div>

				<!-- Description -->
				<div class="mb-10">
					<div class="mb-2 font-semibold text-gray-600">Description</div>
					<textarea
						class="w-full rounded-lg border p-3 text-sm"
						rows="4"
						placeholder=" Add a more detailed description..."
					></textarea>
				</div>
			</div>

			<!-- SIDEBAR -->
			<div class="border-l bg-gray-50 p-6">
				<div class="mb-4 flex items-center justify-between">
					<div class="font-semibold">Comments and activity</div>
				</div>

				<input
					class="mb-4 w-full rounded-lg border px-3 py-2 text-sm"
					placeholder="Write a comment..."
				/>

				<div class="flex items-start gap-3 text-sm">
					<div
						class="flex h-8 w-8 items-center justify-center rounded-full bg-orange-400 font-bold text-white"
					>
						PJ
					</div>
					<div>
						<div class="font-semibold">Per Jylling</div>
						<div class="text-xs text-gray-500">added this card to Doing · just now</div>
					</div>
				</div>
			</div>
		</div>
	</div>

	<!-- <div class="modal-backdrop" on:click={() => (selectedCard = null)}>
		<div class="modal" on:click|stopPropagation>
			<h2>Rediger kort</h2>

			<input class="modal-input" bind:value={selectedCard.title} placeholder="Titel" />

			<textarea class="modal-textarea" placeholder="Beskrivelse..."></textarea>

			<div class="modal-actions">
				<button on:click={() => saveCard(selectedCard)}>Gem</button>
				<button on:click={() => (selectedCard = null)}>Annuller</button>
			</div>
		</div>
	</div> -->
{/if}

<style>
</style>
