export const gestureMethods = {
    get pullRefreshProgress() { return Math.min(1, this.pullDistance / 56); },
    pullRefreshStart(event) {
        if (this.$refs.currencyList?.scrollTop > 0 || this.loading) return;
        this.pullStartY = event.touches[0]?.clientY ?? null;
    },
    pullRefreshMove(event) {
        if (this.pullStartY === null || this.$refs.currencyList?.scrollTop > 0) return;
        const distance = (event.touches[0]?.clientY ?? this.pullStartY) - this.pullStartY;
        if (distance <= 0) return;
        this.pullDistance = Math.min(80, distance * 0.42);
        if (event.cancelable) event.preventDefault();
    },
    pullRefreshEnd() {
        const shouldRefresh = this.pullDistance >= 56 && !this.loading;
        this.pullStartY = null;
        if (!shouldRefresh) {
            this.pullDistance = 0;
            return;
        }
        this.pullDistance = 48;
        this.refreshAll().finally(() => { this.pullDistance = 0; });
    },
    deleteBackgroundOpacity(row) { return Math.min(1, Math.abs(row.swipeOffset) / 100); },
    swipeStart(index, event) {
        this.swipeStartX = event.touches[0]?.clientX ?? null;
        this.swipeStartY = event.touches[0]?.clientY ?? null;
        this.rows[index].swipeOffset = 0; this.swipeGesture = null;
    },
    swipeMove(index, event) {
        if (this.swipeStartX === null || this.swipeStartY === null) return;
        const touch = event.touches[0]; const horizontalDistance = touch.clientX - this.swipeStartX; const verticalDistance = touch.clientY - this.swipeStartY;
        if (Math.abs(verticalDistance) > 8 && Math.abs(verticalDistance) > Math.abs(horizontalDistance)) { this.swipeGesture = 'scroll'; return; }
        if (horizontalDistance < -8 && Math.abs(horizontalDistance) > Math.abs(verticalDistance)) {
            this.swipeGesture = 'swipe'; this.rows[index].swipeOffset = Math.max(horizontalDistance, -180); event.preventDefault();
        }
    },
    swipeEnd(index) {
        const row = this.rows[index];
        if (this.swipeGesture === 'swipe' && row.swipeOffset < -104) this.swipeRemove(index); else row.swipeOffset = 0;
        if (this.swipeGesture) { this.ignoreNextRowClick = true; setTimeout(() => { this.ignoreNextRowClick = false; }, 80); }
        this.swipeStartX = null; this.swipeStartY = null; this.swipeGesture = null;
    },
    swipeRemove(index) {
        if (this.rows.length === 1) return;
        const row = this.rows[index]; row.swipeOffset = -500; this.ignoreNextRowClick = true;
        setTimeout(() => { const currentIndex = this.rows.findIndex((item) => item.id === row.id); if (currentIndex >= 0) this.removeRow(currentIndex); this.ignoreNextRowClick = false; }, 180);
    },
    sortStart(index, event) {
        if (event.pointerType === 'mouse' && event.button !== 0) return;
        this.dragIndex = index; this.sortPointerId = event.pointerId; event.currentTarget.setPointerCapture?.(event.pointerId); this.buzz();
    },
    sortMove(event) {
        if (this.dragIndex === null || event.pointerId !== this.sortPointerId) return;
        const element = document.elementFromPoint(event.clientX, event.clientY)?.closest('[data-currency-row]'); const target = Number(element?.dataset.currencyRow);
        if (Number.isInteger(target) && target >= 0 && target !== this.dragIndex) { this.moveRow(this.dragIndex, target, false); this.dragIndex = target; }
    },
    sortEnd(event) {
        if (event.pointerId !== this.sortPointerId) return;
        this.dragIndex = null; this.sortPointerId = null; this.save();
    },
    moveRow(from, to, withHaptic = true) {
        const [row] = this.rows.splice(from, 1); this.rows.splice(to, 0, row); this.save(); if (withHaptic) this.buzz();
    },
};
