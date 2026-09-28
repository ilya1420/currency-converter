import assert from 'node:assert/strict';
import test from 'node:test';

import { gestureMethods } from '../../resources/js/converter/gestures.js';

function gestureState() {
    const state = {
        rows: [
            { id: 1, currency: 'USD', swipeOffset: 0 },
            { id: 2, currency: 'EUR', swipeOffset: 0 },
        ],
        loading: false,
        pullStartY: null,
        pullDistance: 0,
        swipeStartX: null,
        swipeStartY: null,
        swipeGesture: null,
        ignoreNextRowClick: false,
        dragIndex: null,
        sortPointerId: null,
        $refs: { currencyList: { scrollTop: 0 } },
        buzz() {},
        save() {},
        removeRow(index) { this.rows.splice(index, 1); },
        refreshAll() { return Promise.resolve(); },
    };

    Object.defineProperties(state, Object.getOwnPropertyDescriptors(gestureMethods));
    return state;
}

test('vertical movement never activates row deletion swipe', () => {
    const state = gestureState();
    state.swipeStart(0, { touches: [{ clientX: 100, clientY: 100 }] });
    state.swipeMove(0, {
        touches: [{ clientX: 96, clientY: 140 }],
        preventDefault() { throw new Error('vertical scroll was blocked'); },
    });

    assert.equal(state.swipeGesture, 'scroll');
    assert.equal(state.rows[0].swipeOffset, 0);
});

test('horizontal swipe removes a row only after crossing the threshold', async () => {
    const state = gestureState();
    state.swipeStart(0, { touches: [{ clientX: 200, clientY: 100 }] });
    state.swipeMove(0, {
        touches: [{ clientX: 70, clientY: 102 }],
        preventDefault() {},
    });
    state.swipeEnd(0);
    assert.equal(state.rows.length, 2);

    await new Promise((resolve) => setTimeout(resolve, 210));
    assert.deepEqual(state.rows.map(({ currency }) => currency), ['EUR']);
});

test('moveRow reorders currencies and persists the new order', () => {
    let saves = 0;
    const state = gestureState();
    state.save = () => { saves++; };

    state.moveRow(0, 1, false);

    assert.deepEqual(state.rows.map(({ currency }) => currency), ['EUR', 'USD']);
    assert.equal(saves, 1);
});
