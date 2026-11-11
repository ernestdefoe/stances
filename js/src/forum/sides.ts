import app from 'flarum/forum/app';
import type Mithril from 'mithril';
import type { Side, Stance } from './util';

declare const m: Mithril.Static;

/*
 * The "Show replies from" filter and the viewer's own badges. Small, and needed both by forum.js
 * (the badge and the post's class) and by the card in the lazy chunk.
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

/**
 * After the viewer votes in the discussion's badge block, their own posts' badges change at once.
 * Pushing the attribute bumps each post's freshness, which is what lets its subtree redraw.
 */
export function refreshOwnSides(stance: Stance): void {
  const me = app.session.user;
  const discussion: any = app.store.getById('discussions', String(stance.discussionId));
  if (!me || discussion?.attribute('stanceBadge')?.id !== stance.id) return;

  const tagOn = app.forum.attribute('stancesChangedTag') !== false;
  for (const post of app.store.all<any>('posts')) {
    if (post.user?.() !== me || String(post.discussion?.()?.id?.()) !== String(stance.discussionId)) continue;
    const old: Side | null = post.attribute('stanceSide');
    const posted = old ? (old.was ?? old.label) : null;
    const label = stance.mine === null ? null : (stance.labels?.[stance.mine] ?? null);
    const side: Side | null =
      stance.mine === null ? null : { block: stance.id, choice: stance.mine, label, was: tagOn && posted && posted !== label ? posted : null };
    post.pushAttributes({ stanceSide: side });
  }
}
