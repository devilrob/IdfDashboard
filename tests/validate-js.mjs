import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const views = [
  'resources/views/page.blade.php',
  'resources/views/settings.blade.php',
];

let scriptsChecked = 0;

for (const relative of views) {
  const source = fs.readFileSync(path.join(root, relative), 'utf8');
  const scripts = [...source.matchAll(/^\s*<script>\s*$([\s\S]*?)^\s*<\/script>\s*$/gmi)];

  for (const [index, match] of scripts.entries()) {
    const javascript = match[1]
      .replace(/@json\([^\n]+\)/g, '{}')
      .replace(/\{!![\s\S]*?!!\}/g, 'null')
      .replace(/\{{[\s\S]*?\}}/g, '0');

    new vm.Script(javascript, { filename: `${relative}#script-${index + 1}` });
    scriptsChecked++;
  }
}

if (scriptsChecked === 0) {
  throw new Error('No JavaScript blocks were found.');
}

console.log(`JavaScript syntax: PASS (${scriptsChecked} blocks)`);
