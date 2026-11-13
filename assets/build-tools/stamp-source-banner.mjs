/**
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 *
 * Stamps every compiled bundle with a pointer to its own human-readable source.
 *
 * WordPress.org guideline 4 requires that source to be findable. It ships inside
 * this plugin and readme.txt documents it -- but that is not enough on its own,
 * because the reviewers' scanner quotes ONE LINE out of each minified file and
 * decides from that excerpt alone.
 *
 * WHERE that line is has now bitten this family twice, in two different ways:
 *
 *  - plugixa-team was flagged with its scanner quoting LINE 1, which is why the
 *    four-line block banner below was written.
 *  - plugixa-recipe 1.0.2 was flagged anyway, with the block banner already in
 *    place on all 72 bundles, because THIS scanner walks past a leading comment
 *    block. It quoted `useMutation-*.js:6` -- the first line long enough to look
 *    minified. Measured against the review: exactly 44 files in
 *    assets/backend/build/js/ contain a line longer than 2000 characters, and the
 *    review reported "44 incidences".
 *
 * So the block banner is kept for a human opening the file, and an additional
 * single-line marker is glued to the FRONT OF THE FIRST LONG LINE, with no
 * newline after it. That is the line the scanner quotes, so its own evidence now
 * carries the answer. Attaching it to the first long line rather than to the top
 * of the file matters: freemius-pricing.js already has a one-line licence comment
 * on line 1 and its code on line 2.
 *
 * This runs after `vite build` / the Tailwind CLI rather than as a bundler plugin
 * because neither `output.banner` nor rolldown's plugin-level `banner` addon hook
 * is honoured under Vite 8, and a `generateBundle` prepend cannot see the entry
 * chunk's `__vite__mapDeps` preamble, which Vite injects later.
 *
 * Usage:  node <path>/stamp-source-banner.mjs <backend|frontend|vendor>
 * The backend target makes the same free-or-premium decision as vite.config.ts
 * (PLUGIXA_TARGET=free, or no src/pro/ to build), so it always stamps whichever
 * output tree the build it follows actually wrote.
 *
 * The `vendor` target stamps third-party libraries that are shipped verbatim and
 * therefore sit outside every build directory. Those files are committed, and the
 * release build runs `npm run build` without `npm install`, so the stamp persists
 * -- but re-running `npm install` (frontend) or `composer install` (Freemius)
 * restores the upstream file and drops the stamp. Re-running the build re-applies
 * it, and WporgPreReviewTest fails the build if one is missing.
 */
import { readdirSync, readFileSync, writeFileSync, existsSync, statSync } from 'node:fs';
import { join, resolve, relative, sep } from 'node:path';

const MARKER = 'Plugixa Recipe -- compiled bundle';
const VENDOR_MARKER = 'Plugixa Recipe -- bundled third-party library';
const PLUGIN_ROOT = resolve(import.meta.dirname, '..', '..');

// A line at least this long is what a "is this minified?" heuristic keys on. The
// reviewers' threshold measured at 2000 characters; this is deliberately lower so
// the marker covers more files than they flag, never fewer.
const MIN_MINIFIED_LINE = 500;

const TARGETS = {
  backend: {
    appDir: 'assets/backend/app',
    // Mirrors vite.config.ts's `buildsFree`: PLUGIXA_TARGET=free, OR a tree with no
    // src/pro/ (the free ZIP strips it), writes the base bundle; anything else writes
    // the premium overlay. Deciding on the env var alone sent the documented
    // `npm run build` on the free ZIP looking for pro/assets/backend/build.
    outDir: () =>
      process.env.PLUGIXA_TARGET === 'free' || !existsSync(resolve(PLUGIN_ROOT, 'assets/backend/app/src/pro/index.tsx'))
        ? '../build'
        : '../../../pro/assets/backend/build',
    // SOURCE.txt goes here as well as in outDir, so a reviewer opening the parent
    // of a nested output directory is not met with a lone folder.
    extraReadmeDirs: () => [],
    source: 'assets/backend/app/src/',
    what: 'React admin application (TypeScript, MUI)',
    rebuild: 'cd assets/backend/app && npm install && npm run build',
    node: 'Node.js 20.19+ or 22.12+',
  },
  frontend: {
    // Tailwind CLI: `tailwindcss -i src/frontend.css -o build/css/frontend.css --minify`.
    appDir: 'assets/frontend',
    outDir: () => 'build/css',
    extraReadmeDirs: () => ['build'],
    source: 'assets/frontend/src/',
    what: 'frontend stylesheet (Tailwind CSS 4 entry + hand-written custom.css)',
    rebuild: 'cd assets/frontend && npm install && npm run build',
    node: 'Node.js 20.19+ or 22.12+',
  },
};

/**
 * Third-party libraries shipped verbatim, outside any build directory.
 *
 * Guideline 4 covers "developer libraries you've included", not only first-party
 * code, and each of these carries a long minified line that a scanner will quote.
 * GLightbox is the barest of them: its line 1 is code, with no header at all.
 *
 * The version in each banner is read from the installed package, never typed by hand:
 * the banner states the exact upstream version, and a hard-coded "Swiper 12.1.2" went
 * stale on the first dependency bump while the file under it was 14.x. It is a getter so
 * only the vendor target reads it: the free ZIP has no node_modules, and the backend
 * rebuild documented in SOURCE.txt must still run there.
 */
const vendorLib = (name, pkg) => {
  const manifest = resolve(PLUGIN_ROOT, 'assets/frontend/node_modules', pkg, 'package.json');
  if (!existsSync(manifest)) {
    throw new Error(`stamp-source-banner: ${pkg} is not installed; run npm install in assets/frontend`);
  }
  return `${name} ${JSON.parse(readFileSync(manifest, 'utf8')).version}`;
};

const VENDOR_FILES = [
  {
    file: 'assets/frontend/vendor/swiper/swiper-bundle.min.js',
    get lib() { return vendorLib('Swiper', 'swiper'); },
    license: 'MIT',
    upstream: 'https://github.com/nolimits4web/swiper',
    pinned: 'assets/frontend/package-lock.json',
    vendored: 'copied unmodified from node_modules by the postinstall step in assets/frontend',
  },
  {
    file: 'assets/frontend/vendor/swiper/swiper-bundle.min.css',
    get lib() { return vendorLib('Swiper', 'swiper'); },
    license: 'MIT',
    upstream: 'https://github.com/nolimits4web/swiper',
    pinned: 'assets/frontend/package-lock.json',
    vendored: 'copied unmodified from node_modules by the postinstall step in assets/frontend',
  },
  {
    file: 'assets/frontend/vendor/glightbox/glightbox.min.js',
    get lib() { return vendorLib('GLightbox', 'glightbox'); },
    license: 'MIT',
    upstream: 'https://github.com/biati-digital/glightbox',
    pinned: 'assets/frontend/package-lock.json',
    vendored: 'copied unmodified from node_modules by the postinstall step in assets/frontend',
  },
  {
    file: 'assets/frontend/vendor/glightbox/glightbox.min.css',
    get lib() { return vendorLib('GLightbox', 'glightbox'); },
    license: 'MIT',
    upstream: 'https://github.com/biati-digital/glightbox',
    pinned: 'assets/frontend/package-lock.json',
    vendored: 'copied unmodified from node_modules by the postinstall step in assets/frontend',
  },
  {
    // A DIRECTORY, not a file: the SDK ships 17 js/css assets and 10 of them carry a long
    // minified line. Listing them individually is how the first pass missed nine minified
    // admin stylesheets -- caught only by scanning the built ZIP rather than the source
    // tree. Stamping the whole directory means a future SDK bump cannot reintroduce the gap.
    dir: 'vendor/freemius/wordpress-sdk/assets',
    lib: 'Freemius WordPress SDK 2.13.4',
    license: 'GPL-3.0-only',
    upstream: 'https://github.com/Freemius/wordpress-sdk',
    pinned: 'composer.lock',
    vendored: 'installed by Composer; see composer.json at the plugin root',
  },
];

const name = process.argv[2];

function blockBannerFor(target) {
  return (
    `/*! ${MARKER}. Do not edit; regenerated on every build.\n` +
    ` * Human-readable source: ${target.source} -- ${target.what}\n` +
    ` * Rebuild: ${target.rebuild}   (${target.node})\n` +
    ` * License: GPLv2 or later -- https://www.gnu.org/licenses/gpl-2.0.html\n` +
    ` */\n`
  );
}

function inlineMarkerFor(target) {
  return `/*! ${MARKER} -- source: ${target.source} | rebuild: ${target.rebuild} | GPLv2 or later */`;
}

function vendorBlockBannerFor(entry) {
  return (
    `/*! ${VENDOR_MARKER}, shipped unmodified.\n` +
    ` * ${entry.lib} -- ${entry.license} -- ${entry.upstream}\n` +
    ` * This file is NOT built from this plugin's source. Its human-readable source is the\n` +
    ` * upstream project above; the exact version is pinned in ${entry.pinned}.\n` +
    ` * Vendored by: ${entry.vendored}\n` +
    ` */\n`
  );
}

function vendorInlineMarkerFor(entry) {
  return `/*! ${VENDOR_MARKER}: ${entry.lib} (${entry.license}) -- source: ${entry.upstream} */`;
}

/**
 * Prepend the block banner, and glue the inline marker onto the first line of code.
 *
 * ONLY that line. Marking every long line was tried and is WRONG: a bundle
 * carrying a multi-line template literal minifies to several long lines, and the
 * later ones are CONTINUATIONS of a string, not statements. Prepending a comment
 * there writes the comment text into the string -- measured on
 * dashboard-*.js, where line 8 ended mid-literal at `H${rr}M${2*o},${i}` and the
 * marker landed inside an SVG path. The file still parses, so `node --check` and
 * the build both stay green while the chart renders corrupted.
 *
 * The first line of code is the only placement that is a guaranteed statement
 * boundary, so that is where it goes. "First line of code" means the first line
 * after the leading comment/blank region -- not simply line 1, because
 * freemius-pricing.js opens with a one-line licence comment and swiper with an
 * 11-line header, and the marker belongs on their code, which is the long line a
 * scanner quotes.
 *
 * Returns null when the file is already stamped, so the caller can count skips.
 */
function stamp(code, blockBanner, inlineMarker) {
  if (code.startsWith('/*!') && code.slice(0, 300).includes(MARKER)) return null;
  if (code.startsWith('/*!') && code.slice(0, 300).includes(VENDOR_MARKER)) return null;

  const lines = code.split('\n');
  let i = 0;
  let inComment = false;

  // Walk only the LEADING comment/blank region. This never enters code, so it
  // cannot mistake a `/*` inside a string literal for a comment opener.
  for (; i < lines.length; i += 1) {
    const line = lines[i].trim();
    if (inComment) {
      if (line.includes('*/')) {
        inComment = false;
        // Trailing code on the same line as the comment close: stamp here.
        if (line.slice(line.indexOf('*/') + 2).trim() !== '') break;
      }
      continue;
    }
    if (line === '') continue;
    if (line.startsWith('//')) continue;
    if (line.startsWith('/*')) {
      const end = line.indexOf('*/');
      if (end === -1) inComment = true;
      else if (line.slice(end + 2).trim() !== '') break;
      continue;
    }
    break;
  }

  if (i < lines.length) lines[i] = inlineMarker + lines[i];

  return blockBanner + lines.join('\n');
}

function readmeFor(target) {
  return (
    `Plugixa Recipe -- compiled output\n` +
    `================================\n\n` +
    `Every file in this directory is GENERATED. Do not edit it by hand; the next\n` +
    `build overwrites it.\n\n` +
    `Human-readable source\n` +
    `---------------------\n` +
    `  ${target.source}${' '.repeat(Math.max(1, 30 - target.source.length))}${target.what}\n\n` +
    `Rebuild\n` +
    `-------\n` +
    `  ${target.rebuild}\n` +
    `  (requires ${target.node})\n\n` +
    `Exact dependency versions are pinned in the package-lock.json next to that\n` +
    `source, so the command above reproduces these files. This build emits no\n` +
    `.js.map files -- the source tree itself is the reference, and it ships in\n` +
    `every release ZIP.\n\n` +
    `Note: not every asset this plugin ships is compiled. The frontend scripts in\n` +
    `assets/frontend/js/ and the print stylesheet in assets/frontend/css/ are\n` +
    `hand-written and shipped as-is -- they are the source, and no build step\n` +
    `produces them. The third-party libraries in assets/frontend/vendor/ are\n` +
    `shipped unmodified from their upstream projects; each names its own source\n` +
    `and licence on line 1.\n\n` +
    `License: GPLv2 or later -- https://www.gnu.org/licenses/gpl-2.0.html\n`
  );
}

function walk(dir) {
  const out = [];
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry);
    if (statSync(full).isDirectory()) out.push(...walk(full));
    else out.push(full);
  }
  return out;
}

function runVendor() {
  let stamped = 0;
  let skipped = 0;
  const missing = [];

  for (const entry of VENDOR_FILES) {
    const target = resolve(PLUGIN_ROOT, entry.dir ?? entry.file);
    if (!existsSync(target)) {
      missing.push(entry.dir ?? entry.file);
      continue;
    }

    const paths = entry.dir
      ? walk(target).filter((f) => f.endsWith('.js') || f.endsWith('.css'))
      : [target];

    for (const path of paths) {
      const next = stamp(
        readFileSync(path, 'utf8'),
        vendorBlockBannerFor(entry),
        vendorInlineMarkerFor(entry)
      );

      if (next === null) {
        skipped += 1;
        continue;
      }

      writeFileSync(path, next, 'utf8');
      stamped += 1;
    }
  }

  if (missing.length) {
    console.error(
      `stamp-source-banner: vendor file(s) not found, so nothing names their source:\n  ` +
      missing.join('\n  ')
    );
    process.exit(1);
  }

  console.log(
    `stamp-source-banner: vendor -- stamped ${stamped} file(s)` +
    `${skipped ? `, skipped ${skipped} already stamped` : ''}`
  );
}

function runBuildTarget(target) {
  const outDir = resolve(PLUGIN_ROOT, target.appDir, target.outDir());
  if (!existsSync(outDir)) {
    console.error(`stamp-source-banner: output directory not found: ${outDir}`);
    process.exit(1);
  }

  const blockBanner = blockBannerFor(target);
  const inlineMarker = inlineMarkerFor(target);

  // A prepend of whole lines at offset 0 shifts every mapping down by exactly this
  // many lines; in the VLQ `mappings` format one line break is one `;`. The inline
  // marker adds no newline, so it does not affect this count -- it does shift the
  // columns of the one line it is glued to, which no sourcemap consumer minds for
  // a generated file. (Dormant today: this build emits no .map files.)
  const bannerLines = blockBanner.split('\n').length - 1;

  let stamped = 0;
  let shifted = 0;
  let skipped = 0;

  for (const file of walk(outDir)) {
    if (!file.endsWith('.js') && !file.endsWith('.css')) continue;

    const next = stamp(readFileSync(file, 'utf8'), blockBanner, inlineMarker);
    if (next === null) {
      skipped += 1;
      continue;
    }

    writeFileSync(file, next, 'utf8');
    stamped += 1;

    const mapFile = `${file}.map`;
    if (existsSync(mapFile)) {
      const map = JSON.parse(readFileSync(mapFile, 'utf8'));
      map.mappings = ';'.repeat(bannerLines) + map.mappings;
      writeFileSync(mapFile, JSON.stringify(map), 'utf8');
      shifted += 1;
    }
  }

  const readme = readmeFor(target);
  const readmeDirs = [outDir, ...target.extraReadmeDirs().map((d) => resolve(PLUGIN_ROOT, target.appDir, d))];
  for (const dir of readmeDirs) {
    if (existsSync(dir)) writeFileSync(join(dir, 'SOURCE.txt'), readme, 'utf8');
  }

  const rel = relative(PLUGIN_ROOT, outDir).split(sep).join('/');
  console.log(
    `stamp-source-banner: ${rel} -- stamped ${stamped} file(s), ` +
    `shifted ${shifted} sourcemap(s)${skipped ? `, skipped ${skipped} already stamped` : ''}, wrote SOURCE.txt`
  );
}

if (name === 'vendor') {
  runVendor();
} else if (TARGETS[name]) {
  runBuildTarget(TARGETS[name]);
} else {
  console.error(
    `stamp-source-banner: unknown target "${name}". Expected one of: ${Object.keys(TARGETS).join(', ')}, vendor`
  );
  process.exit(1);
}
