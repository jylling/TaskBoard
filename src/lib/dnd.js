// src/lib/dnd.js



export function draggable(node, data) {
    let state = data;

    node.draggable = true;
    node.style.cursor = 'grab';

    function handle_dragstart(e) {
        if (!e.dataTransfer) return;
        // JSON payload: { type, id }
        e.dataTransfer.setData('application/json', JSON.stringify(state));
        e.dataTransfer.effectAllowed = 'move';
    }

    node.addEventListener('dragstart', handle_dragstart);

    return {
        update(data) {
            state = data;
        },
        destroy() {
            node.removeEventListener('dragstart', handle_dragstart);
        }
    };
}

export function dropzone(node, options) {
    let state = {
        accepts: null, // 'card' | 'column' | null
        dropEffect: 'move',
        dragover_class: 'droppable',
        on_dropzone: () => { },
        ...options
    };

    function readPayload(dt) {
        // Try JSON first
        const json = dt.getData('application/json');
        if (json) {
            try {
                return JSON.parse(json);
            } catch {
                return null;
            }
        }
        // Fallback: old text/plain
        const text = dt.getData('text/plain');
        if (text) return { type: null, id: text };
        return null;
    }

    function handle_dragenter(e) {
        e.preventDefault();
        // IMPORTANT: apply class to the dropzone node (not e.target)
        node.classList.add(state.dragover_class);
    }

    function handle_dragleave(e) {
        // Only remove when leaving the dropzone entirely
        if (e.relatedTarget && node.contains(e.relatedTarget)) return;
        node.classList.remove(state.dragover_class);
    }

    function handle_dragover(e) {
        e.preventDefault();
        if (!e.dataTransfer) return;
        e.dataTransfer.dropEffect = state.dropEffect;
    }

    function handle_drop(e) {
        e.preventDefault();
        if (!e.dataTransfer) return;

        const payload = readPayload(e.dataTransfer);
        if (!payload) return;

        // Type filter
        if (state.accepts && payload.type !== state.accepts) {
            node.classList.remove(state.dragover_class);
            return;
        }

        node.classList.remove(state.dragover_class);
        state.on_dropzone(payload, e);
    }

    node.addEventListener('dragenter', handle_dragenter);
    node.addEventListener('dragleave', handle_dragleave);
    node.addEventListener('dragover', handle_dragover);
    node.addEventListener('drop', handle_drop);

    return {
        update(options) {
            state = {
                accepts: null,
                dropEffect: 'move',
                dragover_class: 'droppable',
                on_dropzone: () => { },
                ...options
            };
        },
        destroy() {
            node.removeEventListener('dragenter', handle_dragenter);
            node.removeEventListener('dragleave', handle_dragleave);
            node.removeEventListener('dragover', handle_dragover);
            node.removeEventListener('drop', handle_drop);
        }
    };
}
