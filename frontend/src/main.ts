import { mount } from 'svelte';
import App from './App.svelte';
import './app.css';
import { readBootstrap } from './bootstrap';

const target = document.getElementById('lexnova-app');
if (!(target instanceof HTMLElement)) {
  throw new Error('LexNova application mount point is missing.');
}

mount(App, {
  target,
  props: {
    bootstrap: readBootstrap(),
  },
});
