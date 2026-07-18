(() => {
    const root = document.querySelector('[data-svg-converter]');
    if (!root) return;

    const input = root.querySelector('#svg-input');
    const output = root.querySelector('#svg-output');
    const file = root.querySelector('#svg-file');
    const convertButton = root.querySelector('[data-convert]');
    const copyButton = root.querySelector('[data-copy]');
    const downloadButton = root.querySelector('[data-download]');
    const status = root.querySelector('[data-status]');

    const numeric = (element, name, fallback = 0) => {
        const value = Number.parseFloat(element.getAttribute(name));
        return Number.isFinite(value) ? value : fallback;
    };

    const number = value => Number.parseFloat(value.toFixed(6)).toString();

    const points = value => {
        const values = (value || '').trim().split(/[\s,]+/).map(Number);
        if (values.length < 4 || values.length % 2 !== 0 || values.some(value => !Number.isFinite(value))) {
            throw new Error('A polygon or polyline contains invalid points.');
        }

        const pairs = [];
        for (let index = 0; index < values.length; index += 2) {
            pairs.push([number(values[index]), number(values[index + 1])]);
        }
        return pairs;
    };

    const shapePath = element => {
        const tag = element.localName.toLowerCase();

        if (tag === 'polygon' || tag === 'polyline') {
            const pairs = points(element.getAttribute('points'));
            return `M ${pairs[0].join(' ')} ${pairs.slice(1).map(pair => `L ${pair.join(' ')}`).join(' ')}${tag === 'polygon' ? ' Z' : ''}`;
        }

        if (tag === 'line') {
            return `M ${number(numeric(element, 'x1'))} ${number(numeric(element, 'y1'))} L ${number(numeric(element, 'x2'))} ${number(numeric(element, 'y2'))}`;
        }

        if (tag === 'circle') {
            const cx = numeric(element, 'cx');
            const cy = numeric(element, 'cy');
            const r = numeric(element, 'r');
            if (r <= 0) throw new Error('A circle has an invalid radius.');
            return `M ${number(cx - r)} ${number(cy)} A ${number(r)} ${number(r)} 0 1 0 ${number(cx + r)} ${number(cy)} A ${number(r)} ${number(r)} 0 1 0 ${number(cx - r)} ${number(cy)} Z`;
        }

        if (tag === 'ellipse') {
            const cx = numeric(element, 'cx');
            const cy = numeric(element, 'cy');
            const rx = numeric(element, 'rx');
            const ry = numeric(element, 'ry');
            if (rx <= 0 || ry <= 0) throw new Error('An ellipse has an invalid radius.');
            return `M ${number(cx - rx)} ${number(cy)} A ${number(rx)} ${number(ry)} 0 1 0 ${number(cx + rx)} ${number(cy)} A ${number(rx)} ${number(ry)} 0 1 0 ${number(cx - rx)} ${number(cy)} Z`;
        }

        if (tag === 'rect') {
            const x = numeric(element, 'x');
            const y = numeric(element, 'y');
            const width = numeric(element, 'width');
            const height = numeric(element, 'height');
            if (width <= 0 || height <= 0) throw new Error('A rectangle has invalid dimensions.');

            let rx = numeric(element, 'rx');
            let ry = numeric(element, 'ry');
            if (element.hasAttribute('rx') && !element.hasAttribute('ry')) ry = rx;
            if (element.hasAttribute('ry') && !element.hasAttribute('rx')) rx = ry;
            rx = Math.min(Math.max(rx, 0), width / 2);
            ry = Math.min(Math.max(ry, 0), height / 2);

            if (!rx && !ry) {
                return `M ${number(x)} ${number(y)} H ${number(x + width)} V ${number(y + height)} H ${number(x)} Z`;
            }

            return `M ${number(x + rx)} ${number(y)} H ${number(x + width - rx)} A ${number(rx)} ${number(ry)} 0 0 1 ${number(x + width)} ${number(y + ry)} V ${number(y + height - ry)} A ${number(rx)} ${number(ry)} 0 0 1 ${number(x + width - rx)} ${number(y + height)} H ${number(x + rx)} A ${number(rx)} ${number(ry)} 0 0 1 ${number(x)} ${number(y + height - ry)} V ${number(y + ry)} A ${number(rx)} ${number(ry)} 0 0 1 ${number(x + rx)} ${number(y)} Z`;
        }

        return null;
    };

    const convert = source => {
        const parser = new DOMParser();
        const documentNode = parser.parseFromString(source, 'image/svg+xml');
        const error = documentNode.querySelector('parsererror');
        if (error || documentNode.documentElement.localName !== 'svg') {
            throw new Error('The input is not valid SVG.');
        }

        const shapeNames = ['polygon', 'polyline', 'line', 'rect', 'circle', 'ellipse'];
        const shapes = [...documentNode.querySelectorAll(shapeNames.join(','))];

        shapes.forEach(shape => {
            const path = documentNode.createElementNS('http://www.w3.org/2000/svg', 'path');
            [...shape.attributes].forEach(attribute => {
                if (!['points', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'width', 'height', 'rx', 'ry', 'cx', 'cy', 'r'].includes(attribute.localName)) {
                    path.setAttributeNS(attribute.namespaceURI, attribute.name, attribute.value);
                }
            });
            path.setAttribute('d', shapePath(shape));
            shape.replaceWith(path);
        });

        const serialized = new XMLSerializer().serializeToString(documentNode.documentElement);
        return { svg: serialized, count: shapes.length };
    };

    const setStatus = (message, isError = false) => {
        status.textContent = message;
        status.classList.toggle('is-error', isError);
    };

    const runConversion = () => {
        try {
            const result = convert(input.value.trim());
            output.value = result.svg;
            copyButton.disabled = false;
            downloadButton.disabled = false;
            setStatus(result.count ? `${result.count} shape${result.count === 1 ? '' : 's'} converted` : 'No convertible shapes found');
        } catch (error) {
            output.value = '';
            copyButton.disabled = true;
            downloadButton.disabled = true;
            setStatus(error.message || 'Conversion failed.', true);
        }
    };

    file.addEventListener('change', async () => {
        const selected = file.files[0];
        if (!selected) return;
        input.value = await selected.text();
        setStatus(`${selected.name} loaded`);
    });

    convertButton.addEventListener('click', runConversion);

    copyButton.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(output.value);
            setStatus('Copied to clipboard');
        } catch {
            output.select();
            document.execCommand('copy');
            setStatus('Copied to clipboard');
        }
    });

    downloadButton.addEventListener('click', () => {
        const blob = new Blob([output.value], { type: 'image/svg+xml;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const anchor = document.createElement('a');
        anchor.href = url;
        anchor.download = 'converted.svg';
        anchor.click();
        URL.revokeObjectURL(url);
        setStatus('Download created');
    });
})();
