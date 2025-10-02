#!/usr/bin/env node
/* eslint-disable no-console */
const fs = require('fs');
const path = require('path');

const metadata = {
  authorName: 'Francesco Passeri',
  authorEmail: 'info@francescopasseri.com',
  authorUri: 'https://francescopasseri.com',
  pluginUri: 'https://francescopasseri.com',
  description:
    'Sincronizza prenotazioni Hotel in Cloud con GA4, Meta e Brevo via webhook e polling sicuro per un tracciamento server-to-server affidabile.',
  contributors: ['francescopasseri'],
  tags: ['analytics', 'ga4', 'meta conversions api', 'brevo', 'hotel booking'],
  supportIssues: 'https://github.com/francescopasseri/FP-Hotel-In-Cloud-Monitoraggio-Conversioni/issues',
};

const args = process.argv.slice(2);
const applyArg = args.find((arg) => arg.startsWith('--apply'));
const apply = applyArg
  ? (() => {
      const parts = applyArg.split('=');
      return parts.length > 1 && typeof parts[1] === 'string'
        ? parts[1].toLowerCase() === 'true'
        : false;
    })()
  : false;
const includeDocs = args.includes('--docs');

const repoRoot = path.resolve(__dirname, '..');
const operations = [];

function updateFile(relativePath, updater, label) {
  const target = path.join(repoRoot, relativePath);
  if (!fs.existsSync(target)) {
    return;
  }

  const original = fs.readFileSync(target, 'utf8');
  const result = updater(original);
  if (!result || typeof result !== 'object') {
    return;
  }

  const { content, touches } = result;
  if (typeof content !== 'string' || content === original) {
    return;
  }

  if (apply) {
    fs.writeFileSync(target, content, 'utf8');
  } else {
    fs.writeFileSync(`${target}.bak`, content, 'utf8');
  }

  operations.push({ file: relativePath, fields: touches || [], mode: apply ? 'updated' : 'dry-run' });
}

function replaceHeaderLine(source, label, value) {
  const regex = new RegExp(`(^\\s*\\* ${label}:\\s*)(.*)$`, 'm');
  if (regex.test(source)) {
    return source.replace(regex, `$1${value}`);
  }

  const pluginNameRegex = /(\* Plugin Name:[^\n]*\n)/;
  if (label === 'Plugin URI' && pluginNameRegex.test(source)) {
    return source.replace(pluginNameRegex, `$1 * ${label}: ${value}\n`);
  }

  const authorRegex = /(\* Author:[^\n]*\n)/;
  if (label === 'Author URI' && authorRegex.test(source)) {
    return source.replace(authorRegex, `$1 * ${label}: ${value}\n`);
  }

  return source;
}

function ensureDocblockTag(source, tag, value) {
  const regex = new RegExp(`(@${tag}\\s+)(.*)`);
  if (regex.test(source)) {
    return source.replace(regex, `$1${value}`);
  }

  const namespaceBlock = /namespace FpHic;\s*\n/;
  if (namespaceBlock.test(source)) {
    return source.replace(
      namespaceBlock,
      `namespace FpHic;\n\n/**\n * @${tag} ${value}\n */\n`
    );
  }

  return source;
}

updateFile(
  'FP-Hotel-In-Cloud-Monitoraggio-Conversioni.php',
  (input) => {
    let output = input;
    const touches = [];

    const headerFields = [
      ['Plugin URI', metadata.pluginUri],
      ['Description', metadata.description],
      ['Author', metadata.authorName],
      ['Author URI', metadata.authorUri],
    ];

    headerFields.forEach(([label, value]) => {
      const updated = replaceHeaderLine(output, label, value);
      if (updated !== output) {
        output = updated;
        touches.push(label);
      }
    });

    const docTags = [
      ['author', metadata.authorName],
      ['link', metadata.authorUri],
    ];

    docTags.forEach(([tag, value]) => {
      const updated = ensureDocblockTag(output, tag, value);
      if (updated !== output) {
        output = updated;
        touches.push(`@${tag}`);
      }
    });

    return { content: output, touches };
  },
  'plugin-header'
);

updateFile('readme.txt', (input) => {
  let output = input;
  const touches = [];

  const scalarFields = [
    ['Contributors', metadata.contributors.join(', ')],
    ['Tags', metadata.tags.join(', ')],
    ['Requires at least', '5.8'],
    ['Tested up to', '6.6'],
    ['Requires PHP', '7.4'],
    ['Stable tag', '3.4.1'],
    ['Plugin URI', metadata.pluginUri],
    ['Author', metadata.authorName],
    ['Author URI', metadata.authorUri],
  ];

  scalarFields.forEach(([label, value]) => {
    const regex = new RegExp(`(^${label}:\\s*)(.*)$`, 'm');
    if (regex.test(output)) {
      const updated = output.replace(regex, `$1${value}`);
      if (updated !== output) {
        output = updated;
        touches.push(label);
      }
    }
  });

  if (!output.includes(metadata.description)) {
    output = output.replace(/== Description ==\n[\s\S]*?\n\n/, (match) => {
      const replacement = `== Description ==\n${metadata.description}\n\n`;
      if (!touches.includes('Description')) {
        touches.push('Description');
      }
      return replacement;
    });
  }

  return { content: output, touches };
});

updateFile('README.md', (input) => {
  let output = input;
  const touches = [];

  const tableFields = new Map([
    ['Versione', '3.4.1'],
    ['Autore', `[${metadata.authorName}](${metadata.authorUri}) ([${metadata.authorEmail}](mailto:${metadata.authorEmail}))`],
    ['Autore URI', metadata.authorUri],
    ['Plugin URI', metadata.pluginUri],
    ['Requires at least', 'WordPress 5.8'],
    ['Tested up to', 'WordPress 6.6'],
    ['Requires PHP', '7.4'],
  ]);

  tableFields.forEach((value, label) => {
    const regex = new RegExp(`(^\\| ${label} \\|\\s*)([^\\|]*)(\\|$)`, 'm');
    if (regex.test(output)) {
      const updated = output.replace(regex, `$1${value} |`);
      if (updated !== output) {
        output = updated;
        touches.push(label);
      }
    }
  });

  if (!output.includes(metadata.description)) {
    output = output.replace(
      /# FP HIC Monitor\n\n[\s\S]*?\n\n## Plugin information/,
      `# FP HIC Monitor\n\n${metadata.description}\n\n## Plugin information`
    );
    touches.push('What it does');
  }

  return { content: output, touches };
});

updateFile('composer.json', (input) => {
  const touches = [];
  const json = JSON.parse(input);
  let changed = false;

  if (json.homepage !== metadata.pluginUri) {
    json.homepage = metadata.pluginUri;
    touches.push('homepage');
    changed = true;
  }

  if (!json.support || json.support.issues !== metadata.supportIssues) {
    json.support = json.support || {};
    json.support.issues = metadata.supportIssues;
    touches.push('support.issues');
    changed = true;
  }

  const authors = [
    {
      name: metadata.authorName,
      email: metadata.authorEmail,
      homepage: metadata.authorUri,
      role: 'Developer',
    },
  ];

  if (JSON.stringify(json.authors) !== JSON.stringify(authors)) {
    json.authors = authors;
    touches.push('authors');
    changed = true;
  }

  if (!json.scripts) {
    json.scripts = {};
  }

  const desiredScripts = {
    'sync:author': ["sh -lc 'node tools/sync-author-metadata.js --apply=\"${APPLY:-false}\"'"],
    'sync:docs': ["sh -lc 'node tools/sync-author-metadata.js --docs --apply=\"${APPLY:-false}\"'"],
    'changelog:from-git': ["sh -lc 'conventional-changelog -p angular -i CHANGELOG.md -s || true'"],
  };

  Object.entries(desiredScripts).forEach(([key, commandList]) => {
    const current = Array.isArray(json.scripts[key]) ? json.scripts[key] : json.scripts[key] ? [json.scripts[key]] : [];
    if (JSON.stringify(current) !== JSON.stringify(commandList)) {
      json.scripts[key] = commandList;
      touches.push(`scripts.${key}`);
      changed = true;
    }
  });

  if (!changed) {
    return { content: input, touches: [] };
  }

  const content = `${JSON.stringify(json, null, 4)}\n`;
  return { content, touches };
});

if (includeDocs) {
  const docTargets = [
    'docs/overview.md',
    'docs/architecture.md',
    'docs/faq.md',
  ];

  docTargets.forEach((file) => {
    updateFile(file, (input) => {
      if (!input.includes(metadata.description)) {
        const content = input.replace(
          /(Sincronizza prenotazioni Hotel in Cloud[^\n]*\.)/,
          metadata.description
        );
        if (content !== input) {
          return { content, touches: ['description'] };
        }
      }
      return { content: input, touches: [] };
    });
  });
}

if (operations.length === 0) {
  console.log('No changes required.');
  process.exit(0);
}

console.log('Sync author metadata results:');
console.table(
  operations.map((op) => ({
    File: op.file,
    Fields: op.fields.join(', '),
    Mode: op.mode,
  }))
);

process.exit(0);
