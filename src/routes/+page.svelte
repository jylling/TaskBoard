<script lang="ts">
	import { ssrModuleExportsKey } from 'vite/module-runner';
	import Board from './Board.svelte';
	import { lang } from '$lib/stores/lang'; // Adjust the import path to match your i18n setup

	let board = [];
	let darkMode = false;

	$: {
		document.documentElement.classList.toggle('dark', darkMode);
	}

	async function initLoadBoard() {
		//	board = await fetch('api.php?action=getBoard').then((r) => r.json());
		//board = await fetch('api.php?action=getBoard').then((r) => r.json());
	}

	//	initLoadBoard();
</script>

<button
	class:dark-btn={darkMode}
	class:light-btn={!darkMode}
	on:click={() => (darkMode = !darkMode)}
>
	{darkMode ? 'Light mode' : 'Dark mode'}
</button>
<button on:click={() => lang.set('da')}>Dansk</button>
<button on:click={() => lang.set('en')}>Engelsk</button>
<button on:click={() => lang.set('th')}>Thai</button>

<!-- <Board columnItems={board}></Board> -->
