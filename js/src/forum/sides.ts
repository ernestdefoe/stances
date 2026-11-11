import app from 'flarum/forum/app';
import type Mithril from 'mithril';

declare const m: Mithril.Static;

/*
 * The "Show replies from" filter. It points the discussion's post stream at the post ids the
 * server returns for one side (core's stream reads discussion.postIds() every time), so a long
 * discussion pages through that side alone. forum.js needs it to clear the filter when the
 * discussion page goes away; the card in the lazy chunk fetches the ids and applies them.
 */

let current: number | null = null;
let filtered: any = null;

export function sideFilter(): number | null {
  return current;
}

/** ids null puts the whole discussion back. */
export function applySideFilter(discussion: any, value: number | null, ids: string[] | null): void {
  if (filtered && filtered !== discussion) delete filtered.postIds;
  current = value;
  if (ids) {
    discussion.postIds = () => ids;
    filtered = discussion;
  } else {
    delete discussion.postIds;
    filtered = null;
  }
  const stream: any = app.current.get('stream');
  if (stream?.discussion === discussion) stream.goToFirst();
  m.redraw();
}

export function clearSideFilter(): void {
  if (filtered) delete filtered.postIds;
  filtered = null;
  current = null;
}
