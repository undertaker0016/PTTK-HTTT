(function () {
    const data = window.dashboardData || { monthly: [], categories: [], todos: [] };
    const pieColors = ['#635BFF', '#0EA5E9', '#22A06B', '#A855F7', '#38BDF8', '#7C4DFF'];
    const formatBits = [
        0b111011111000100,
        0b111001011110011,
        0b111110110101010,
        0b111100010011101,
        0b110011000101111,
        0b110001100011000,
        0b110110001000001,
        0b110100101110110,
    ];
    const gfExp = new Array(512).fill(0);
    const gfLog = new Array(256).fill(0);
    const alphanumericCharset = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ $%*+-./:';
    let gfInitialized = false;

    function formatMoney(value) {
        return new Intl.NumberFormat('vi-VN').format(value) + ' VND';
    }

    function escapeHtml(value) {
        return String(value)
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#39;');
    }

    function roundedRectPath(ctx, x, y, width, height, radius) {
        const safeRadius = Math.min(radius, width / 2, height / 2);
        ctx.beginPath();
        ctx.moveTo(x + safeRadius, y);
        ctx.lineTo(x + width - safeRadius, y);
        ctx.quadraticCurveTo(x + width, y, x + width, y + safeRadius);
        ctx.lineTo(x + width, y + height - safeRadius);
        ctx.quadraticCurveTo(x + width, y + height, x + width - safeRadius, y + height);
        ctx.lineTo(x + safeRadius, y + height);
        ctx.quadraticCurveTo(x, y + height, x, y + height - safeRadius);
        ctx.lineTo(x, y + safeRadius);
        ctx.quadraticCurveTo(x, y, x + safeRadius, y);
        ctx.closePath();
    }

    function setupCanvas(canvas, fallbackWidth, fallbackHeight) {
        const ctx = canvas.getContext('2d');
        const dpr = window.devicePixelRatio || 1;
        const width = canvas.clientWidth || fallbackWidth;
        const height = Number(canvas.getAttribute('height')) || fallbackHeight;

        canvas.width = width * dpr;
        canvas.height = height * dpr;
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.clearRect(0, 0, width, height);
        ctx.imageSmoothingEnabled = false;

        return { ctx, width, height };
    }

    function renderMonthlyChart() {
        const canvas = document.getElementById('monthlyChart');
        if (!canvas) {
            return;
        }

        const { ctx, width, height } = setupCanvas(canvas, 680, 250);
        const points = data.monthly || [];
        const values = points.map((item) => Number(item.value) || 0);
        const maxValue = Math.max(...values, 1);
        const padding = { top: 18, right: 14, bottom: 44, left: 18 };
        const chartWidth = width - padding.left - padding.right;
        const chartHeight = height - padding.top - padding.bottom;
        const gap = 14;
        const barWidth = Math.max(28, chartWidth / Math.max(points.length, 1) - gap);

        ctx.strokeStyle = 'rgba(31, 111, 115, 0.14)';
        ctx.lineWidth = 1;
        for (let i = 0; i <= 4; i += 1) {
            const y = padding.top + (chartHeight / 4) * i;
            ctx.beginPath();
            ctx.moveTo(padding.left, y);
            ctx.lineTo(width - padding.right, y);
            ctx.stroke();
        }

        points.forEach((point, index) => {
            const value = Number(point.value) || 0;
            const barHeight = (value / maxValue) * (chartHeight - 8);
            const x = padding.left + index * (barWidth + gap) + 6;
            const y = padding.top + chartHeight - barHeight;

            const gradient = ctx.createLinearGradient(x, y, x, padding.top + chartHeight);
            gradient.addColorStop(0, '#635BFF');
            gradient.addColorStop(1, '#0EA5E9');

            ctx.fillStyle = gradient;
            roundedRectPath(ctx, x, y, barWidth, barHeight, 14);
            ctx.fill();

            ctx.fillStyle = '#5b5249';
            ctx.font = '12px Segoe UI';
            ctx.textAlign = 'center';
            ctx.fillText(point.label, x + barWidth / 2, height - 14);

            if (value > 0) {
                ctx.fillStyle = '#1d1916';
                ctx.font = 'bold 11px Segoe UI';
                ctx.fillText(new Intl.NumberFormat('vi-VN').format(value), x + barWidth / 2, Math.max(y - 8, 14));
            }
        });
    }

    function renderCategoryChart() {
        const container = document.getElementById('categoryChart');
        if (!container) {
            return;
        }

        const items = data.categories || [];
        if (!items.length) {
            container.innerHTML = '<div class="empty-state">Thêm dữ liệu chi tiêu để hiển thị biểu đồ danh mục.</div>';
            return;
        }

        const maxValue = Math.max(...items.map((item) => Number(item.value) || 0), 1);
        container.innerHTML = items
            .map((item) => {
                const value = Number(item.value) || 0;
                const width = Math.max(8, Math.round((value / maxValue) * 100));
                return `
                    <div class="category-row">
                        <strong>${escapeHtml(item.label)}</strong>
                        <div class="category-row__track">
                            <div class="category-row__bar" style="width: ${width}%"></div>
                        </div>
                        <span>${formatMoney(value)}</span>
                    </div>
                `;
            })
            .join('');
    }

    function renderTodoChart() {
        const container = document.getElementById('todoChart');
        if (!container) {
            return;
        }

        const items = data.todos || [];
        const maxValue = Math.max(...items.map((item) => Number(item.value) || 0), 1);
        container.innerHTML = items
            .map((item) => {
                const value = Number(item.value) || 0;
                const width = Math.max(10, Math.round((value / maxValue) * 100));
                const modifier = item.label === 'Hoàn thành' ? 'done' : 'pending';
                return `
                    <div class="todo-row">
                        <strong>${escapeHtml(item.label)}</strong>
                        <div class="todo-row__track">
                            <div class="todo-row__bar todo-row__bar--${modifier}" style="width: ${width}%"></div>
                        </div>
                        <span>${value}</span>
                    </div>
                `;
            })
            .join('');
    }

    function renderExpensePieChart() {
        const canvas = document.getElementById('expensePieChart');
        const legend = document.getElementById('expensePieLegend');
        if (!canvas || !legend) {
            return;
        }

        const items = data.categories || [];
        if (!items.length) {
            legend.innerHTML = '<div class="empty-state">Chưa có dữ liệu để chia phần trăm chi tiêu theo nhóm.</div>';
            return;
        }

        const { ctx, width, height } = setupCanvas(canvas, 320, 240);
        const total = items.reduce((sum, item) => sum + (Number(item.value) || 0), 0);
        const centerX = width / 2;
        const centerY = height / 2;
        const radius = Math.min(width, height) / 2 - 14;
        const innerRadius = radius * 0.56;

        let startAngle = -Math.PI / 2;
        items.forEach((item, index) => {
            const value = Number(item.value) || 0;
            const sliceAngle = total > 0 ? (value / total) * Math.PI * 2 : 0;
            const endAngle = startAngle + sliceAngle;
            const color = pieColors[index % pieColors.length];

            ctx.beginPath();
            ctx.moveTo(centerX, centerY);
            ctx.arc(centerX, centerY, radius, startAngle, endAngle);
            ctx.closePath();
            ctx.fillStyle = color;
            ctx.fill();

            startAngle = endAngle;
        });

        ctx.beginPath();
        ctx.arc(centerX, centerY, innerRadius, 0, Math.PI * 2);
        ctx.fillStyle = '#fffdf9';
        ctx.fill();

        ctx.fillStyle = '#6b6054';
        ctx.textAlign = 'center';
        ctx.font = '12px Segoe UI';
        ctx.fillText('Tổng chi', centerX, centerY - 4);
        ctx.fillStyle = '#1d1916';
        ctx.font = 'bold 15px Segoe UI';
        ctx.fillText(new Intl.NumberFormat('vi-VN').format(total), centerX, centerY + 18);

        legend.innerHTML = items
            .map((item, index) => {
                const value = Number(item.value) || 0;
                const percent = total > 0 ? Math.round((value / total) * 100) : 0;
                const color = pieColors[index % pieColors.length];
                return `
                    <div class="pie-legend__item">
                        <span class="pie-legend__swatch" style="background:${color}"></span>
                        <span>${escapeHtml(item.label)} - ${percent}%</span>
                        <span class="pie-legend__value">${formatMoney(value)}</span>
                    </div>
                `;
            })
            .join('');
    }

    function initGaloisField() {
        if (gfInitialized) {
            return;
        }

        let value = 1;
        for (let i = 0; i < 255; i += 1) {
            gfExp[i] = value;
            gfLog[value] = i;
            value <<= 1;
            if (value & 0x100) {
                value ^= 0x11d;
            }
        }

        for (let i = 255; i < 512; i += 1) {
            gfExp[i] = gfExp[i - 255];
        }

        gfInitialized = true;
    }

    function gfMul(x, y) {
        if (x === 0 || y === 0) {
            return 0;
        }
        return gfExp[gfLog[x] + gfLog[y]];
    }

    function polyMultiply(a, b) {
        const result = new Array(a.length + b.length - 1).fill(0);
        for (let i = 0; i < a.length; i += 1) {
            for (let j = 0; j < b.length; j += 1) {
                result[i + j] ^= gfMul(a[i], b[j]);
            }
        }
        return result;
    }

    function makeGeneratorPolynomial(degree) {
        let generator = [1];
        for (let i = 0; i < degree; i += 1) {
            generator = polyMultiply(generator, [1, gfExp[i]]);
        }
        return generator;
    }

    function computeErrorCorrection(dataCodewords, degree) {
        const generator = makeGeneratorPolynomial(degree);
        const result = dataCodewords.concat(new Array(degree).fill(0));

        for (let i = 0; i < dataCodewords.length; i += 1) {
            const factor = result[i];
            if (factor === 0) {
                continue;
            }
            for (let j = 0; j < generator.length; j += 1) {
                result[i + j] ^= gfMul(generator[j], factor);
            }
        }

        return result.slice(result.length - degree);
    }

    function appendBits(bits, value, length) {
        for (let i = length - 1; i >= 0; i -= 1) {
            bits.push((value >>> i) & 1);
        }
    }

    function encodeAlphanumeric(text) {
        if (text.length > 25) {
            throw new Error('Số điện thoại quá dài cho mẫu QR offline hiện tại.');
        }

        const bits = [];
        appendBits(bits, 0b0010, 4);
        appendBits(bits, text.length, 9);

        for (let i = 0; i < text.length; i += 2) {
            const first = alphanumericCharset.indexOf(text[i]);
            if (first < 0) {
                throw new Error('Dữ liệu chứa ký tự không hỗ trợ cho QR offline.');
            }

            if (i + 1 < text.length) {
                const second = alphanumericCharset.indexOf(text[i + 1]);
                if (second < 0) {
                    throw new Error('Dữ liệu chứa ký tự không hỗ trợ cho QR offline.');
                }
                appendBits(bits, first * 45 + second, 11);
            } else {
                appendBits(bits, first, 6);
            }
        }

        const capacity = 19 * 8;
        appendBits(bits, 0, Math.min(4, capacity - bits.length));
        while (bits.length % 8 !== 0) {
            bits.push(0);
        }

        const codewords = [];
        for (let i = 0; i < bits.length; i += 8) {
            let value = 0;
            for (let j = 0; j < 8; j += 1) {
                value = (value << 1) | bits[i + j];
            }
            codewords.push(value);
        }

        const padBytes = [0xec, 0x11];
        let padIndex = 0;
        while (codewords.length < 19) {
            codewords.push(padBytes[padIndex % 2]);
            padIndex += 1;
        }

        return codewords.concat(computeErrorCorrection(codewords, 7));
    }

    function createMatrix(size) {
        return Array.from({ length: size }, () => Array(size).fill(null));
    }

    function createFunctionMatrix(size) {
        return Array.from({ length: size }, () => Array(size).fill(false));
    }

    function setFunctionCell(matrix, isFunction, x, y, value) {
        if (x < 0 || y < 0 || x >= matrix.length || y >= matrix.length) {
            return;
        }
        matrix[y][x] = value;
        isFunction[y][x] = true;
    }

    function drawFinder(matrix, isFunction, x, y) {
        for (let dy = -1; dy <= 7; dy += 1) {
            for (let dx = -1; dx <= 7; dx += 1) {
                const xx = x + dx;
                const yy = y + dy;
                const inside = dx >= 0 && dx <= 6 && dy >= 0 && dy <= 6;
                const isBlack =
                    inside &&
                    (dx === 0 ||
                        dx === 6 ||
                        dy === 0 ||
                        dy === 6 ||
                        (dx >= 2 && dx <= 4 && dy >= 2 && dy <= 4));
                setFunctionCell(matrix, isFunction, xx, yy, isBlack);
            }
        }
    }

    function reserveFormatAreas(matrix, isFunction) {
        const size = matrix.length;
        for (let i = 0; i < 9; i += 1) {
            if (i !== 6) {
                setFunctionCell(matrix, isFunction, 8, i, false);
                setFunctionCell(matrix, isFunction, i, 8, false);
            }
        }

        for (let i = 0; i < 8; i += 1) {
            setFunctionCell(matrix, isFunction, size - 1 - i, 8, false);
            setFunctionCell(matrix, isFunction, 8, size - 1 - i, false);
        }

        setFunctionCell(matrix, isFunction, 8, size - 8, true);
    }

    function drawTimingPatterns(matrix, isFunction) {
        const size = matrix.length;
        for (let i = 8; i < size - 8; i += 1) {
            const value = i % 2 === 0;
            setFunctionCell(matrix, isFunction, i, 6, value);
            setFunctionCell(matrix, isFunction, 6, i, value);
        }
    }

    function drawFormatBits(matrix, mask) {
        const size = matrix.length;
        const bits = formatBits[mask];
        const getBit = (value, index) => ((value >>> index) & 1) !== 0;

        for (let i = 0; i <= 5; i += 1) {
            matrix[8][i] = getBit(bits, i);
        }
        matrix[8][7] = getBit(bits, 6);
        matrix[8][8] = getBit(bits, 7);
        matrix[7][8] = getBit(bits, 8);
        for (let i = 9; i < 15; i += 1) {
            matrix[14 - i][8] = getBit(bits, i);
        }

        for (let i = 0; i < 8; i += 1) {
            matrix[size - 1 - i][8] = getBit(bits, i);
        }
        for (let i = 8; i < 15; i += 1) {
            matrix[8][size - 15 + i] = getBit(bits, i);
        }

        matrix[size - 8][8] = true;
    }

    function maskFormula(mask, x, y) {
        switch (mask) {
            case 0:
                return (x + y) % 2 === 0;
            case 1:
                return y % 2 === 0;
            case 2:
                return x % 3 === 0;
            case 3:
                return (x + y) % 3 === 0;
            case 4:
                return (Math.floor(y / 2) + Math.floor(x / 3)) % 2 === 0;
            case 5:
                return ((x * y) % 2) + ((x * y) % 3) === 0;
            case 6:
                return ((((x * y) % 2) + ((x * y) % 3)) % 2) === 0;
            case 7:
                return ((((x + y) % 2) + ((x * y) % 3)) % 2) === 0;
            default:
                return false;
        }
    }

    function applyMask(matrix, isFunction, mask) {
        const size = matrix.length;
        for (let y = 0; y < size; y += 1) {
            for (let x = 0; x < size; x += 1) {
                if (!isFunction[y][x] && maskFormula(mask, x, y)) {
                    matrix[y][x] = !matrix[y][x];
                }
            }
        }
    }

    function placeDataBits(matrix, isFunction, codewords) {
        const bits = [];
        codewords.forEach((codeword) => appendBits(bits, codeword, 8));

        const size = matrix.length;
        let bitIndex = 0;
        let upwards = true;

        for (let right = size - 1; right >= 1; right -= 2) {
            if (right === 6) {
                right -= 1;
            }

            for (let i = 0; i < size; i += 1) {
                const y = upwards ? size - 1 - i : i;
                for (let j = 0; j < 2; j += 1) {
                    const x = right - j;
                    if (isFunction[y][x]) {
                        continue;
                    }
                    matrix[y][x] = bitIndex < bits.length ? bits[bitIndex] === 1 : false;
                    bitIndex += 1;
                }
            }

            upwards = !upwards;
        }
    }

    function cloneMatrix(matrix) {
        return matrix.map((row) => row.slice());
    }

    function penaltyScore(matrix) {
        const size = matrix.length;
        let score = 0;

        for (let y = 0; y < size; y += 1) {
            let runColor = matrix[y][0];
            let runLength = 1;
            for (let x = 1; x < size; x += 1) {
                if (matrix[y][x] === runColor) {
                    runLength += 1;
                } else {
                    if (runLength >= 5) {
                        score += 3 + (runLength - 5);
                    }
                    runColor = matrix[y][x];
                    runLength = 1;
                }
            }
            if (runLength >= 5) {
                score += 3 + (runLength - 5);
            }
        }

        for (let x = 0; x < size; x += 1) {
            let runColor = matrix[0][x];
            let runLength = 1;
            for (let y = 1; y < size; y += 1) {
                if (matrix[y][x] === runColor) {
                    runLength += 1;
                } else {
                    if (runLength >= 5) {
                        score += 3 + (runLength - 5);
                    }
                    runColor = matrix[y][x];
                    runLength = 1;
                }
            }
            if (runLength >= 5) {
                score += 3 + (runLength - 5);
            }
        }

        for (let y = 0; y < size - 1; y += 1) {
            for (let x = 0; x < size - 1; x += 1) {
                const value = matrix[y][x];
                if (
                    value === matrix[y][x + 1] &&
                    value === matrix[y + 1][x] &&
                    value === matrix[y + 1][x + 1]
                ) {
                    score += 3;
                }
            }
        }

        const finderPattern1 = '10111010000';
        const finderPattern2 = '00001011101';

        for (let y = 0; y < size; y += 1) {
            const row = matrix[y].map((cell) => (cell ? '1' : '0')).join('');
            for (let i = 0; i <= size - 11; i += 1) {
                const slice = row.slice(i, i + 11);
                if (slice === finderPattern1 || slice === finderPattern2) {
                    score += 40;
                }
            }
        }

        for (let x = 0; x < size; x += 1) {
            let column = '';
            for (let y = 0; y < size; y += 1) {
                column += matrix[y][x] ? '1' : '0';
            }
            for (let i = 0; i <= size - 11; i += 1) {
                const slice = column.slice(i, i + 11);
                if (slice === finderPattern1 || slice === finderPattern2) {
                    score += 40;
                }
            }
        }

        let darkCount = 0;
        for (let y = 0; y < size; y += 1) {
            for (let x = 0; x < size; x += 1) {
                if (matrix[y][x]) {
                    darkCount += 1;
                }
            }
        }
        const total = size * size;
        const percent = (darkCount * 100) / total;
        score += Math.floor(Math.abs(percent - 50) / 5) * 10;

        return score;
    }

    function generateQrMatrix(text) {
        initGaloisField();

        const size = 21;
        const baseMatrix = createMatrix(size);
        const isFunction = createFunctionMatrix(size);

        drawFinder(baseMatrix, isFunction, 0, 0);
        drawFinder(baseMatrix, isFunction, size - 7, 0);
        drawFinder(baseMatrix, isFunction, 0, size - 7);
        drawTimingPatterns(baseMatrix, isFunction);
        reserveFormatAreas(baseMatrix, isFunction);

        const codewords = encodeAlphanumeric(text);
        placeDataBits(baseMatrix, isFunction, codewords);

        let bestMatrix = null;
        let bestScore = Infinity;

        for (let mask = 0; mask < 8; mask += 1) {
            const candidate = cloneMatrix(baseMatrix);
            applyMask(candidate, isFunction, mask);
            drawFormatBits(candidate, mask);
            const score = penaltyScore(candidate);
            if (score < bestScore) {
                bestScore = score;
                bestMatrix = candidate;
            }
        }

        return bestMatrix;
    }

    function sanitizePhone(phone) {
        const trimmed = phone.trim();
        const hasPlus = trimmed.startsWith('+');
        const digits = trimmed.replace(/\D/g, '');
        return hasPlus ? `+${digits}` : digits;
    }

    function buildPhonePayload(phone) {
        const cleanPhone = sanitizePhone(phone);
        if (!cleanPhone) {
            return '';
        }
        return `TEL:${cleanPhone}`;
    }

    function drawPlaceholder(canvas, message) {
        const { ctx, width, height } = setupCanvas(canvas, 260, 260);
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, width, height);
        ctx.strokeStyle = 'rgba(89, 65, 38, 0.12)';
        ctx.lineWidth = 1;
        roundedRectPath(ctx, 12, 12, width - 24, height - 24, 18);
        ctx.stroke();

        ctx.fillStyle = '#8c421c';
        ctx.textAlign = 'center';
        ctx.font = 'bold 16px Segoe UI';
        ctx.fillText('QR Offline', width / 2, height / 2 - 8);
        ctx.fillStyle = '#655c54';
        ctx.font = '13px Segoe UI';
        ctx.fillText(message, width / 2, height / 2 + 18);
    }

    function drawQrMatrix(canvas, matrix, size) {
        canvas.setAttribute('width', String(size));
        canvas.setAttribute('height', String(size));
        canvas.style.width = `${size}px`;
        canvas.style.height = `${size}px`;

        const { ctx, width, height } = setupCanvas(canvas, size, size);
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, width, height);

        const quietZone = 4;
        const modules = matrix.length + quietZone * 2;
        const moduleSize = Math.min(width, height) / modules;
        const actualSize = moduleSize * modules;
        const offsetX = (width - actualSize) / 2;
        const offsetY = (height - actualSize) / 2;

        ctx.fillStyle = '#111';
        for (let y = 0; y < matrix.length; y += 1) {
            for (let x = 0; x < matrix.length; x += 1) {
                if (!matrix[y][x]) {
                    continue;
                }
                ctx.fillRect(
                    offsetX + (x + quietZone) * moduleSize,
                    offsetY + (y + quietZone) * moduleSize,
                    moduleSize,
                    moduleSize
                );
            }
        }
    }

    function initOfflineQrGenerator() {
        const form = document.getElementById('offlineQrForm');
        const phoneInput = document.getElementById('qrPhoneInput');
        const sizeInput = document.getElementById('qrSizeInput');
        const payloadPreview = document.getElementById('qrPayloadPreview');
        const canvas = document.getElementById('offlineQrCanvas');
        const readableText = document.getElementById('qrReadableText');
        const statusText = document.getElementById('qrStatusText');
        const downloadButton = document.getElementById('qrDownloadButton');
        const clearButton = document.getElementById('qrClearButton');
        const sampleButton = document.getElementById('qrSampleButton');

        if (!form || !phoneInput || !sizeInput || !payloadPreview || !canvas || !readableText || !statusText) {
            return;
        }

        function updatePayloadPreview() {
            const payload = buildPhonePayload(phoneInput.value);
            payloadPreview.value = payload || 'TEL:0901234567';
        }

        function generate() {
            const payload = buildPhonePayload(phoneInput.value);
            const size = Number(sizeInput.value) || 260;

            if (!payload) {
                drawPlaceholder(canvas, 'Nhập số điện thoại');
                readableText.textContent = 'Nhập số điện thoại rồi bấm tạo mã QR.';
                statusText.textContent = 'Ứng dụng sẽ tạo mã QR offline trực tiếp trên máy của bạn.';
                return;
            }

            try {
                const matrix = generateQrMatrix(payload);
                drawQrMatrix(canvas, matrix, size);
                readableText.textContent = payload;
                statusText.textContent = 'Quét mã này trên điện thoại sẽ nhận số điện thoại và có thể gọi nhanh.';
            } catch (error) {
                drawPlaceholder(canvas, 'Không tạo được QR');
                readableText.textContent = payload;
                statusText.textContent = error instanceof Error ? error.message : 'Có lỗi khi tạo QR offline.';
            }
        }

        form.addEventListener('submit', (event) => {
            event.preventDefault();
            updatePayloadPreview();
            generate();
        });

        phoneInput.addEventListener('input', updatePayloadPreview);
        sizeInput.addEventListener('change', generate);

        sampleButton?.addEventListener('click', () => {
            phoneInput.value = '0901234567';
            updatePayloadPreview();
            generate();
        });

        clearButton?.addEventListener('click', () => {
            phoneInput.value = '';
            updatePayloadPreview();
            drawPlaceholder(canvas, 'Nhập số điện thoại');
            readableText.textContent = 'Nhập số điện thoại rồi bấm tạo mã QR.';
            statusText.textContent = 'Ứng dụng sẽ tạo mã QR offline trực tiếp trên máy của bạn.';
        });

        downloadButton?.addEventListener('click', () => {
            const payload = buildPhonePayload(phoneInput.value);
            if (!payload) {
                statusText.textContent = 'Hãy nhập số điện thoại và tạo QR trước khi tải.';
                return;
            }

            const link = document.createElement('a');
            const fileName = `phone-qr-${sanitizePhone(phoneInput.value) || 'offline'}.png`;
            link.href = canvas.toDataURL('image/png');
            link.download = fileName;
            link.click();
        });

        updatePayloadPreview();
        drawPlaceholder(canvas, 'Nhập số điện thoại');
    }

    function initThemeToggle() {
        const button = document.getElementById('themeToggleButton');
        const label = document.getElementById('themeToggleLabel');
        if (!button || !label) {
            return;
        }

        const storageKey = 'lifeboard-theme';
        const preferredTheme = localStorage.getItem(storageKey);
        const systemDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;

        function applyTheme(theme) {
            document.body.setAttribute('data-theme', theme);
            button.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
            label.textContent = theme === 'dark' ? 'Chế độ sáng' : 'Chế độ tối';
        }

        applyTheme(preferredTheme || (systemDark ? 'dark' : 'light'));

        button.addEventListener('click', () => {
            const currentTheme = document.body.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
            const nextTheme = currentTheme === 'dark' ? 'light' : 'dark';
            localStorage.setItem(storageKey, nextTheme);
            applyTheme(nextTheme);
        });
    }

    function renderAll() {
        renderMonthlyChart();
        renderCategoryChart();
        renderTodoChart();
        renderExpensePieChart();
        initOfflineQrGenerator();
        initThemeToggle();
    }

    renderAll();
    window.addEventListener('resize', () => {
        renderMonthlyChart();
        renderExpensePieChart();
    });
})();
