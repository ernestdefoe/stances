import type Mithril from 'mithril';

declare const m: Mithril.Static;

/*
 * The "Show replies from" filter. Small, and needed both by forum.js (leaving the discussion
 * clears it) and by the card in the lazy chunk.
 */

let current: number | null = null;

export function sideFilter(): number | null {
  return current;
}

/** Hides replies from the other side (CSS on the body class); the opening post always stays. */
export function setSideFilter(value: number | null): void {
  current = value;
  document.body.classList.remove('Stances-only-0', 'Stances-only-1');
  if (value !== null) document.body.classList.add(`Stances-only-${value}`);
  m.redraw();
}
