import fs from 'node:fs';

const file = new URL('./request-count-per-target.csv', import.meta.url);
const output = new URL('./request-count-per-target.svg', import.meta.url);
const rows = fs.readFileSync(file, 'utf8').trim().split(/\r?\n/)
  .filter((line) => /^\d{4}\/\d{2}\/\d{2} /.test(line))
  .map((line) => {
    const [time, value] = line.split(',');
    return { time: time.slice(11, 16), value: Number(value) };
  })
  .filter((point) => Number.isFinite(point.value));

if (rows.length < 2) throw new Error('Need at least two CloudWatch datapoints');

const width = 1000;
const height = 430;
const left = 74;
const right = 30;
const top = 52;
const bottom = 75;
const plotWidth = width - left - right;
const plotHeight = height - top - bottom;
const max = Math.max(250, Math.ceil(Math.max(...rows.map((point) => point.value)) / 50) * 50);
const labelStep = Math.max(2, Math.ceil(rows.length / 12));
const x = (index) => left + index * plotWidth / (rows.length - 1);
const y = (value) => top + plotHeight * (1 - value / max);
const escape = (text) => text.replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;');
const series = rows.map((point, index) => `${x(index).toFixed(1)},${y(point.value).toFixed(1)}`).join(' ');
const ticks = [0, 50, 100, 150, 200, 250].filter((value) => value <= max);
const grid = ticks.map((value) => `<line x1="${left}" y1="${y(value)}" x2="${width-right}" y2="${y(value)}" stroke="#dce5ec"/><text x="${left-12}" y="${y(value)+4}" text-anchor="end" font-size="14" fill="#40566a">${value}</text>`).join('');
const labels = rows.map((point, index) => index % labelStep === 0 || index === rows.length - 1
  ? `<text x="${x(index)}" y="${height-bottom+27}" text-anchor="middle" font-size="12" fill="#40566a">${escape(point.time)}</text>`
  : '').join('');
const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}" viewBox="0 0 ${width} ${height}">
<rect width="${width}" height="${height}" fill="#ffffff"/>
<text x="${left}" y="29" font-family="Arial, sans-serif" font-size="20" font-weight="bold" fill="#172b3a">RequestCountPerTarget (CloudWatch CSV)</text>
<g font-family="Arial, sans-serif">${grid}
<line x1="${left}" y1="${y(50)}" x2="${width-right}" y2="${y(50)}" stroke="#c83535" stroke-width="2" stroke-dasharray="7 5"/>
<text x="${width-right-4}" y="${y(50)-8}" text-anchor="end" font-size="13" fill="#b12828">AlarmHigh: 50</text>
<polyline points="${series}" fill="none" stroke="#1976a3" stroke-width="3" stroke-linejoin="round" stroke-linecap="round"/>
${rows.map((point, index) => `<circle cx="${x(index)}" cy="${y(point.value)}" r="3" fill="#1976a3"/>`).join('')}
${labels}
<text x="${width/2}" y="${height-17}" text-anchor="middle" font-size="13" fill="#40566a">8 ноября 2026, UTC</text>
<text x="${left}" y="${height-42}" font-size="12" fill="#40566a">Источник: экспорт AWS CloudWatch; построено из CSV, не снимок консоли.</text>
</g></svg>\n`;
fs.writeFileSync(output, svg);
console.log(`${rows.length} points -> ${output.pathname}`);
