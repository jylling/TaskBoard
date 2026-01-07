import { writable, derived } from 'svelte/store';
import da from '$lib/i18n/da';
import en from '$lib/i18n/en';
import th from '$lib/i18n/th';

const dict = { da, en, th };

export const lang = writable<'da' | 'en' | 'th'>('en');

export const t = derived(lang, $lang => dict[$lang]);
