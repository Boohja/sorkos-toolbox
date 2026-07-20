(() => {
    const dialog = document.querySelector('[data-tool-search]');

    if (!(dialog instanceof HTMLDialogElement)) {
        return;
    }

    const input = dialog.querySelector('[data-tool-search-input]');
    const triggers = document.querySelectorAll('[data-tool-search-trigger]');
    const closeButton = dialog.querySelector('[data-tool-search-close]');
    const emptyMessage = dialog.querySelector('[data-tool-search-empty]');
    const status = dialog.querySelector('[data-tool-search-status]');
    const items = Array.from(dialog.querySelectorAll('[data-tool-search-result]'));
    let visibleItems = items;
    let activeIndex = -1;
    let openDialogHeight = 0;
    let closeTimer = 0;
    let closeTransitionHandler = null;
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

    const searchableText = new Map(items.map((item) => {
        const shorthand = item.querySelector('.tool-search-shorthand')?.textContent ?? '';
        const name = item.querySelector('.tool-search-name')?.textContent ?? '';

        return [item, `${shorthand} ${name}`.toLocaleLowerCase()];
    }));

    const selectItem = (index, scroll = false) => {
        activeIndex = visibleItems.length === 0
            ? -1
            : (index + visibleItems.length) % visibleItems.length;

        items.forEach((item) => item.setAttribute('aria-selected', 'false'));

        const activeItem = visibleItems[activeIndex];
        input.removeAttribute('aria-activedescendant');

        if (!activeItem) {
            return;
        }

        activeItem.setAttribute('aria-selected', 'true');
        input.setAttribute('aria-activedescendant', activeItem.id);

        if (scroll) {
            activeItem.scrollIntoView({ block: 'nearest' });
        }
    };

    const filterItems = () => {
        const query = input.value.trim().toLocaleLowerCase();

        visibleItems = items.filter((item) => {
            const matches = searchableText.get(item).includes(query);
            item.hidden = !matches;
            return matches;
        });

        emptyMessage.hidden = visibleItems.length !== 0;
        status.textContent = `${visibleItems.length} ${visibleItems.length === 1 ? 'tool' : 'tools'} found.`;
        selectItem(0);
    };

    const cancelScheduledClose = () => {
        window.clearTimeout(closeTimer);
        closeTimer = 0;

        if (closeTransitionHandler) {
            dialog.removeEventListener('transitionend', closeTransitionHandler);
            closeTransitionHandler = null;
        }

        dialog.classList.remove('is-closing');
    };

    const openSearch = () => {
        if (dialog.open) {
            cancelScheduledClose();
        }

        if (!dialog.open) {
            dialog.style.removeProperty('height');
            input.value = '';
            filterItems();
            dialog.showModal();
            openDialogHeight = dialog.getBoundingClientRect().height;
            dialog.style.height = `${openDialogHeight}px`;
            input.setAttribute('aria-expanded', 'true');
        }

        requestAnimationFrame(() => input.focus({ preventScroll: true }));
    };

    const closeSearch = () => {
        if (!dialog.open || dialog.classList.contains('is-closing')) {
            return;
        }

        if (reducedMotion.matches) {
            dialog.close();
            return;
        }

        dialog.classList.add('is-closing');

        const finishClose = () => {
            cancelScheduledClose();

            if (dialog.open) {
                dialog.close();
            }
        };

        closeTransitionHandler = (event) => {
            if (event.target === dialog && event.propertyName === 'opacity') {
                finishClose();
            }
        };

        dialog.addEventListener('transitionend', closeTransitionHandler);
        closeTimer = window.setTimeout(finishClose, 240);
    };

    document.addEventListener('keydown', (event) => {
        if (
            event.ctrlKey
            && !event.metaKey
            && !event.altKey
            && event.key.toLocaleLowerCase() === 'p'
        ) {
            event.preventDefault();
            openSearch();
        }
    });

    dialog.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            event.preventDefault();
            closeSearch();
        } else if (event.key === 'ArrowDown') {
            event.preventDefault();
            selectItem(activeIndex + 1, true);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            selectItem(activeIndex - 1, true);
        } else if (event.key === 'Enter' && visibleItems[activeIndex]) {
            event.preventDefault();
            window.location.assign(visibleItems[activeIndex].href);
        }
    });

    input.addEventListener('input', filterItems);
    triggers.forEach((trigger) => trigger.addEventListener('click', openSearch));
    closeButton.addEventListener('click', closeSearch);

    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) {
            closeSearch();
        }
    });

    dialog.addEventListener('cancel', (event) => {
        event.preventDefault();
        closeSearch();
    });

    dialog.addEventListener('close', () => {
        cancelScheduledClose();
        dialog.style.removeProperty('height');
        openDialogHeight = 0;
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
    });

    window.addEventListener('resize', () => {
        if (!dialog.open || openDialogHeight === 0) {
            return;
        }

        const viewportGap = window.matchMedia('(max-width: 520px)').matches ? 24 : 48;
        dialog.style.height = `${Math.min(openDialogHeight, window.innerHeight - viewportGap)}px`;
    });

    items.forEach((item) => {
        item.addEventListener('pointerenter', () => {
            const index = visibleItems.indexOf(item);
            if (index !== -1) {
                selectItem(index);
            }
        });
    });
})();
