import { fractionToDecimal, parseExpression } from './decimal.js';

export const calculatorMethods = {
    isOperator(key) { return ['+', '-', '*', '/', '%'].includes(key); },
    toggleKeyboard() { this.keyboardVisible = !this.keyboardVisible; this.save(); this.buzz(); },
    showKeyboard() {
        if (this.keyboardVisible) return;
        this.keyboardVisible = true;
        this.save();
        this.buzz();
    },
    get activeAmountLabel() {
        if (!this.isFreshInput && /[+\-*/%]/.test(this.amount)) return this.amount.replace('*', '×').replace('/', '÷');
        const activeRow = this.rows.find((row) => row.currency === this.base);
        return this.formatAmount(activeRow?.result || this.amount);
    },
    previewCalculation() {
        try {
            this.recalculate(fractionToDecimal(parseExpression(this.amount), this.inputFractionDigits()));
        } catch {}
    },
    press(key) {
        this.buzz();
        if (key === 'C') return this.clear();
        if (key === '⌫') return this.backspace();
        if (this.isOperator(key)) {
            if (!this.amount || /[+\-*/]$/.test(this.amount)) return;
            this.amount += key; this.isFreshInput = false;
        } else if (key === '.') {
            // A selected amount is still the amount being edited.  Do not
            // replace it with zero merely because the decimal key was tapped.
            this.isFreshInput = false;
            const lastNumber = this.amount.split(/[+\-*/]/).at(-1);
            if (!lastNumber.includes('.')) this.amount += key;
        } else {
            // Converted values retain precision internally. When a row has just
            // been selected, the next digit replaces that value, so its old
            // fractional length must not block the input.
            if (!this.isFreshInput && this.amount.split(/[+\-*/]/).at(-1).split('.')[1]?.length >= this.inputFractionDigits()) return;
            this.amount = this.isFreshInput || this.amount === '0' ? key : this.amount + key;
            this.isFreshInput = false;
        }
        this.displayAmount = this.amount;
        this.previewCalculation();
        this.save?.();
    },
    backspace() {
        this.isFreshInput = false;
        this.amount = this.amount.slice(0, -1) || '0'; this.displayAmount = this.amount; this.previewCalculation(); this.save?.();
    },
    clear() { this.amount = '0'; this.displayAmount = '0'; this.isFreshInput = true; this.previewCalculation(); this.save?.(); },
    calculate() {
        try {
            this.amount = fractionToDecimal(parseExpression(this.amount), this.inputFractionDigits());
            this.displayAmount = this.amount; this.isFreshInput = true; this.recalculate(); this.save?.();
        } catch { this.message = 'Проверьте выражение.'; }
        this.buzz();
    },
};
