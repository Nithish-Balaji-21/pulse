/**
 * IoT Insights - Day Card Details Popover
 *
 * Provides a rich, interactive hover/click-to-pin details popover for
 * calendar all-day production cards (Pith, CFT, Shifts, Machine & Energy).
 */
(function (window, document) {
    'use strict';

    let popoverEl = null;
    let currentCardEl = null;
    let currentData = null;
    let isPinned = false;
    let hideTimer = null;

    function formatNumber(val) {
        if (typeof window.formatToIndianNumber === 'function') {
            return window.formatToIndianNumber(val);
        }
        return Number(val || 0).toLocaleString('en-IN');
    }

    function createPopoverEl() {
        if (popoverEl) return popoverEl;

        popoverEl = document.createElement('div');
        popoverEl.id = 'iot-details-popover';
        popoverEl.className = 'iot-details-popover';
        popoverEl.style.cssText = `
            position: absolute;
            z-index: 1060;
            width: 340px;
            max-width: calc(100vw - 24px);
            background: #ffffff;
            border: 1px solid #e7e5e4;
            border-radius: 16px;
            box-shadow: 0 12px 32px -4px rgba(0, 0, 0, 0.12), 0 4px 10px -2px rgba(0, 0, 0, 0.05);
            padding: 14px 16px 12px;
            font-family: inherit;
            display: none;
            opacity: 0;
            transition: opacity 0.15s ease, transform 0.15s ease;
            transform: translateY(-4px);
            pointer-events: auto;
        `;

        // Keep open when mouse enters the popover
        popoverEl.addEventListener('mouseenter', () => {
            if (hideTimer) {
                clearTimeout(hideTimer);
                hideTimer = null;
            }
        });

        popoverEl.addEventListener('mouseleave', () => {
            if (!isPinned) {
                IotDetailsPopover.scheduleHide(150);
            }
        });

        // Delegate close button click
        popoverEl.addEventListener('click', (e) => {
            const closeBtn = e.target.closest('.popover-close-btn');
            if (closeBtn) {
                e.stopPropagation();
                IotDetailsPopover.unpin();
            }
        });

        document.body.appendChild(popoverEl);
        return popoverEl;
    }

    function renderPopoverHtml(data, pinned) {
        const basis = data.basisFormatted || formatNumber(data.basis || 0);

        // Chip styling
        let chipBg = '#f3f4f6';
        let chipColor = '#6b7280';
        let chipBorder = '#e5e7eb';

        if (data.statusType === 'all_actual' || data.statusType === 'partial_actual') {
            chipBg = '#eef4ff';
            chipColor = '#2563eb';
            chipBorder = '#dbeafe';
        } else if (data.statusType === 'estimate') {
            chipBg = '#fef3c7';
            chipColor = '#b45309';
            chipBorder = '#fde68a';
        }

        const chipHtml = data.statusChip
            ? `<span style="background:${chipBg}; color:${chipColor}; border:1px solid ${chipBorder}; font-size:11px; font-weight:600; padding:2px 8px; border-radius:6px; line-height:1.4;">${data.statusChip}</span>`
            : '';

        // Shifts list
        const shifts = data.shifts || [];
        let shiftsHtml = '';
        if (shifts.length === 0) {
            shiftsHtml = '<div style="font-size:12px; color:#a8a29e; font-style:italic; padding:4px 0;">No shift recorded</div>';
        } else {
            shiftsHtml = shifts.map(shift => {
                const label = shift.label || ('S' + (shift.sequence || 1));
                const color = shift.color || '#83211b';

                let deltaHtml = '';
                let numbersHtml = '';

                if (shift.is_entered) {
                    const enteredStr = shift.enteredFormatted || formatNumber(shift.entered);
                    const meteredStr = shift.meteredFormatted || formatNumber(shift.metered);
                    numbersHtml = `<strong style="font-weight:600; color:#1c1917;">${enteredStr}</strong> <span style="color:#78716c;">· ${meteredStr} cft</span>`;

                    if (shift.delta !== null && shift.delta !== undefined) {
                        const isNeg = shift.delta < 0;
                        const isPos = shift.delta > 0;
                        const dColor = isNeg ? '#dc2626' : (isPos ? '#16a34a' : '#57534e');
                        const dBg = isNeg ? '#fee2e2' : (isPos ? '#dcfce7' : '#f3f4f6');
                        const dBorder = isNeg ? '#fecaca' : (isPos ? '#bbf7d0' : '#e5e7eb');
                        const sign = isPos ? '+' : '';
                        deltaHtml = `<span style="background:${dBg}; color:${dColor}; border:1px solid ${dBorder}; font-size:11px; font-weight:600; padding:1px 6px; border-radius:4px; font-variant-numeric:tabular-nums; line-height:1.3;">${sign}${shift.delta}%</span>`;
                    }
                } else {
                    const meteredStr = shift.meteredFormatted || formatNumber(shift.metered);
                    numbersHtml = `<strong style="font-weight:600; color:#1c1917;">${meteredStr}</strong> <span style="color:#78716c;">cft</span>`;
                    deltaHtml = `<span style="background:#f3f4f6; color:#78716c; font-size:10.5px; font-weight:500; padding:1px 5px; border-radius:4px;">est</span>`;
                }

                return `
                    <div style="display:flex; align-items:center; justify-content:space-between; padding:4px 0; gap:8px;">
                        <div style="display:flex; align-items:center; gap:8px; min-width:0;">
                            <span style="background:${color}; color:#ffffff; font-size:10px; font-weight:700; padding:1px 5px; border-radius:4px; line-height:1.3; flex-shrink:0;">${label}</span>
                            <span style="font-size:13px; font-weight:500; color:#292524; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${shift.name}</span>
                        </div>
                        <div style="display:flex; align-items:center; gap:6px; flex-shrink:0; font-size:12.5px; font-variant-numeric:tabular-nums;">
                            ${numbersHtml}
                            ${deltaHtml}
                        </div>
                    </div>
                `;
            }).join('');
        }

        // Machine & Energy
        const machine = data.machine || {};
        const states = machine.states || [];
        let statePillsHtml = '';

        if (states.length > 0) {
            statePillsHtml = states.map(st => `
                <span style="display:inline-flex; align-items:center; gap:5px; background:#ffffff; border:1px solid #e7e5e4; border-radius:6px; padding:3px 7px; font-size:11px; color:#44403c; font-variant-numeric:tabular-nums; white-space:nowrap;">
                    <span style="width:6px; height:6px; border-radius:50%; background:${st.color}; display:inline-block; flex-shrink:0;"></span>
                    <span>${st.label}: <strong style="font-weight:600; color:#1c1917;">${st.formatted}</strong></span>
                </span>
            `).join('');
        } else {
            statePillsHtml = '<span style="font-size:11.5px; color:#a8a29e; font-style:italic;">No state telemetry</span>';
        }

        const footerText = pinned
            ? '<span style="color:#2563eb; font-weight:500;">Pinned · Click × or outside to close</span>'
            : 'Click card to pin details';

        return `
            <!-- Header -->
            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:10px;">
                <div style="display:flex; align-items:center; gap:6px; min-width:0;">
                    <svg style="width:14px; height:14px; color:#6b7280; flex-shrink:0;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                        <path d="M16 2v4M8 2v4M3 10h18"></path>
                    </svg>
                    <span style="font-size:13.5px; font-weight:600; color:#1c1917; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                        ${data.dateFormatted || data.date}
                    </span>
                </div>
                <button type="button" class="popover-close-btn" title="Close" style="width:22px; height:22px; display:flex; align-items:center; justify-content:center; border-radius:6px; color:#9ca3af; cursor:pointer; border:none; background:transparent; font-size:16px; line-height:1; transition:all 0.15s ease;">
                    &times;
                </button>
            </div>

            <!-- Big CFT Total Card -->
            <div style="background:#faf8f5; border:1px solid #eee8e0; border-radius:12px; padding:10px 14px; display:flex; align-items:center; justify-content:space-between; margin-bottom:12px;">
                <div>
                    <span style="font-size:20px; font-weight:700; color:#1c1917; font-variant-numeric:tabular-nums; line-height:1.1;">${basis}</span>
                    <span style="font-size:12.5px; font-weight:400; color:#78716c; margin-left:3px;">cft</span>
                </div>
                <div>
                    ${chipHtml}
                </div>
            </div>

            <!-- Shift Breakdown -->
            <div style="margin-bottom:12px;">
                <div style="font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.05em; color:#a8a29e; margin-bottom:6px;">
                    SHIFT BREAKDOWN
                </div>
                <div style="display:flex; flex-direction:column; gap:2px;">
                    ${shiftsHtml}
                </div>
            </div>

            <!-- Machine & Energy -->
            <div style="margin-bottom:10px;">
                <div style="font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.05em; color:#a8a29e; margin-bottom:6px;">
                    MACHINE & ENERGY
                </div>
                <div style="background:#faf8f5; border:1px solid #eee8e0; border-radius:12px; padding:10px 14px; margin-bottom:8px; display:flex; justify-content:space-between; align-items:flex-start;">
                    <div>
                        <div style="font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.04em; color:#a8a29e; margin-bottom:3px;">RUN TIME</div>
                        <div style="display:flex; align-items:center; gap:5px; font-size:14px; font-weight:700; color:#1c1917; font-variant-numeric:tabular-nums;">
                            <span style="width:7px; height:7px; border-radius:50%; background:#16a34a; display:inline-block;"></span>
                            ${machine.runningFormatted || '0h'}
                        </div>
                    </div>
                    <div style="text-align:right;">
                        <div style="font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.04em; color:#a8a29e; margin-bottom:3px;">EB POWER COST</div>
                        <div style="font-size:14px; font-weight:700; color:#1c1917; font-variant-numeric:tabular-nums;">
                            ${machine.ebPriceFormatted || '—'}
                        </div>
                    </div>
                </div>

                <!-- State Pills -->
                <div style="display:flex; flex-wrap:wrap; gap:5px;">
                    ${statePillsHtml}
                </div>
            </div>

            <!-- Footer -->
            <div style="padding-top:8px; border-top:1px solid #f5f3f0; font-size:11px; color:#a8a29e; display:flex; align-items:center; justify-content:space-between;">
                <span>${footerText}</span>
            </div>
        `;
    }

    function positionPopover(targetEl) {
        if (!popoverEl || !targetEl) return;

        const popoverWidth = popoverEl.offsetWidth || 340;
        const popoverHeight = popoverEl.offsetHeight || 360;
        const targetRect = targetEl.getBoundingClientRect();

        // Calculate horizontal position: centered on target, clamped within viewport
        let left = targetRect.left + (targetRect.width / 2) - (popoverWidth / 2) + window.scrollX;
        const minLeft = 12 + window.scrollX;
        const maxLeft = window.innerWidth - popoverWidth - 12 + window.scrollX;
        left = Math.max(minLeft, Math.min(maxLeft, left));

        // Calculate vertical position: prefer above the target card
        // Check if there is enough space above the card (away from top sticky nav/toolbar)
        const spaceAbove = targetRect.top;
        const spaceBelow = window.innerHeight - targetRect.bottom;

        let top;
        if (spaceAbove >= popoverHeight + 80) {
            // Position above
            top = targetRect.top - popoverHeight - 8 + window.scrollY;
        } else if (spaceBelow >= popoverHeight + 20) {
            // Position below
            top = targetRect.bottom + 8 + window.scrollY;
        } else {
            // Not enough space above or below, place where there is more room
            if (spaceAbove > spaceBelow) {
                top = Math.max(70, targetRect.top - popoverHeight - 8) + window.scrollY;
            } else {
                top = targetRect.bottom + 8 + window.scrollY;
            }
        }

        popoverEl.style.left = `${Math.round(left)}px`;
        popoverEl.style.top = `${Math.round(top)}px`;
    }

    const IotDetailsPopover = {
        show(cardEl, data, pinned = false) {
            if (hideTimer) {
                clearTimeout(hideTimer);
                hideTimer = null;
            }

            // If already pinned to another card and this is just a hover on a different card, ignore
            if (isPinned && !pinned && currentCardEl !== cardEl) {
                return;
            }

            if (!cardEl || !data) return;

            const popover = createPopoverEl();
            currentCardEl = cardEl;
            currentData = data;
            if (pinned) {
                isPinned = true;
            }

            popover.innerHTML = renderPopoverHtml(data, isPinned);
            popover.style.display = 'block';

            // Position after content rendered
            positionPopover(cardEl);

            // Animate in
            requestAnimationFrame(() => {
                popover.style.opacity = '1';
                popover.style.transform = 'translateY(0)';
            });

            if (isPinned) {
                document.querySelectorAll('.ec-all-day-custom-total').forEach(el => el.classList.remove('is-pinned'));
                cardEl.classList.add('is-pinned');
            }
        },

        scheduleHide(delay = 150) {
            if (isPinned) return;
            if (hideTimer) clearTimeout(hideTimer);
            hideTimer = setTimeout(() => {
                IotDetailsPopover.hide();
            }, delay);
        },

        hide(force = false) {
            if (hideTimer) {
                clearTimeout(hideTimer);
                hideTimer = null;
            }
            if (isPinned && !force) return;

            if (popoverEl) {
                popoverEl.style.opacity = '0';
                popoverEl.style.transform = 'translateY(-4px)';
                setTimeout(() => {
                    if (!isPinned || force) {
                        popoverEl.style.display = 'none';
                    }
                }, 150);
            }

            if (currentCardEl) {
                currentCardEl.classList.remove('is-pinned');
            }

            if (force) {
                isPinned = false;
                currentCardEl = null;
                currentData = null;
            }
        },

        pin(cardEl, data) {
            // If already pinned to this exact card, toggle off
            if (isPinned && currentCardEl === cardEl) {
                this.unpin();
                return;
            }

            this.show(cardEl, data, true);
        },

        unpin() {
            this.hide(true);
        },

        isPinned() {
            return isPinned;
        },

        attach(cardEl, data) {
            if (!cardEl || !data) return;

            // Strip native title from inner elements so default tooltip does not conflict
            cardEl.querySelectorAll('[title]').forEach(el => {
                const titleVal = el.getAttribute('title');
                if (titleVal) {
                    el.setAttribute('data-native-title', titleVal);
                    el.removeAttribute('title');
                }
            });

            cardEl.style.cursor = 'pointer';

            cardEl.addEventListener('mouseenter', () => {
                IotDetailsPopover.show(cardEl, data, false);
            });

            cardEl.addEventListener('mouseleave', () => {
                IotDetailsPopover.scheduleHide(150);
            });

            cardEl.addEventListener('click', (e) => {
                e.stopPropagation();
                IotDetailsPopover.pin(cardEl, data);
            });
        }
    };

    // Close on click outside
    document.addEventListener('click', (e) => {
        if (!isPinned) return;
        if (popoverEl && (popoverEl.contains(e.target) || e.target === popoverEl)) {
            return;
        }
        if (currentCardEl && (currentCardEl.contains(e.target) || e.target === currentCardEl)) {
            return;
        }
        IotDetailsPopover.unpin();
    });

    // Close on Escape
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            IotDetailsPopover.unpin();
        }
    });

    // Reposition on resize/scroll if visible
    window.addEventListener('resize', () => {
        if (popoverEl && popoverEl.style.display !== 'none' && currentCardEl) {
            positionPopover(currentCardEl);
        }
    });

    window.IotDetailsPopover = IotDetailsPopover;

})(window, document);
