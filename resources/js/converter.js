import { calculatorMethods } from './converter/calculator.js';
import { chartMethods } from './converter/charts.js';
import { conversionMethods } from './converter/conversion.js';
import { currencyListMethods } from './converter/currency-list.js';
import { gestureMethods } from './converter/gestures.js';
import { createConverterState } from './converter/state.js';

function attachMethods(state, methods) {
    Object.defineProperties(state, Object.getOwnPropertyDescriptors(methods));
    return state;
}

window.converter = (catalog) => [
    calculatorMethods,
    chartMethods,
    conversionMethods,
    currencyListMethods,
    gestureMethods,
].reduce(attachMethods, createConverterState(catalog));
