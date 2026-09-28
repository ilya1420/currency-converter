export function gestureSlice() {
    return {
        dragIndex: null,
        sortPointerId: null,
        swipeStartX: null,
        swipeStartY: null,
        swipeGesture: null,
        pullStartY: null,
        pullDistance: 0,
    };
}
