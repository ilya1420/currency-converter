import assert from 'node:assert/strict';
import test from 'node:test';

import { calculatorMethods } from '../../resources/js/converter/calculator.js';

function calculatorState({ amount = '100', digits = 2 } = {}) {
    const state = {
        amount,
        displayAmount: amount,
        isFreshInput: true,
        rows: [{ currency: 'USD', result: amount }],
        base: 'USD',
        keyboardVisible: false,
        buzz() { this.buzzCalls = (this.buzzCalls || 0) + 1; },
        save() { this.saveCalls = (this.saveCalls || 0) + 1; },
        formatAmount(value) { return value; },
        inputFractionDigits() { return digits; },
        recalculate(value) { this.lastCalculated = value; },
    };
    Object.defineProperties(state, Object.getOwnPropertyDescriptors(calculatorMethods));

    return state;
}

test('showKeyboard opens the keypad and persists the visible state', () => {
    const state = calculatorState();

    state.showKeyboard();

    assert.equal(state.keyboardVisible, true);
    assert.equal(state.saveCalls, 1);
    assert.equal(state.buzzCalls, 1);
});

test('showKeyboard does not toggle an already visible keypad', () => {
    const state = calculatorState();
    state.keyboardVisible = true;

    state.showKeyboard();

    assert.equal(state.keyboardVisible, true);
    assert.equal(state.saveCalls, undefined);
    assert.equal(state.buzzCalls, undefined);
});

test('first digit replaces a selected converted value with extra precision', () => {
    const state = calculatorState({ amount: '251.624361234' });

    state.press('7');

    assert.equal(state.amount, '7');
    assert.equal(state.displayAmount, '7');
    assert.equal(state.lastCalculated, '7');
});

test('decimal key edits the selected value without resetting it to zero', () => {
    const state = calculatorState({ amount: '100' });

    state.press('.');
    state.press('5');

    assert.equal(state.amount, '100.5');
    assert.equal(state.lastCalculated, '100.5');
});

test('crypto input accepts up to six fractional digits', () => {
    const state = calculatorState({ amount: '0', digits: 6 });
    state.isFreshInput = false;

    ['.', '1', '2', '3', '4', '5', '6', '7'].forEach((key) => state.press(key));

    assert.equal(state.amount, '0.123456');
});

test('calculator previews expressions before equals is pressed', () => {
    const state = calculatorState({ amount: '1' });
    state.isFreshInput = false;

    state.press('+');
    state.press('2');

    assert.equal(state.amount, '1+2');
    assert.equal(state.lastCalculated, '3');
});
