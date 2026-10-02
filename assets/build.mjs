// Builds public/build/{app.css,app.js} and a manifest.json used by Symfony's asset() helper.
//   node assets/build.mjs          production build (minified + fingerprinted file names)
//   node assets/build.mjs --watch  development watch mode (stable file names)
import { spawn } from 'node:child_process';
import { createHash } from 'node:crypto';
import { mkdir, readFile, readdir, rm, writeFile, copyFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import * as esbuild from 'esbuild';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const outDir = path.join(root, 'public', 'build');
const watch = process.argv.includes('--watch');
const tailwindBin = path.join(root, 'node_modules', '.bin', 'tailwindcss');

function run(cmd, args) {
    return new Promise((resolve, reject) => {
        const child = spawn(cmd, args, { cwd: root, stdio: 'inherit' });
        child.on('exit', (code) => (code === 0 ? resolve() : reject(new Error(`${cmd} exited with ${code}`))));
    });
}

async function writeManifest(entries) {
    await writeFile(path.join(outDir, 'manifest.json'), JSON.stringify(entries, null, 2) + '\n');
}

async function fingerprint(file) {
    const content = await readFile(path.join(outDir, file));
    const hash = createHash('sha256').update(content).digest('hex').slice(0, 10);
    const { name, ext } = path.parse(file);
    const hashed = `${name}.${hash}${ext}`;
    await copyFile(path.join(outDir, file), path.join(outDir, hashed));
    return hashed;
}

await rm(outDir, { recursive: true, force: true });
await mkdir(outDir, { recursive: true });

const jsOptions = {
    entryPoints: [path.join(root, 'assets/js/app.js')],
    outfile: path.join(outDir, 'app.js'),
    bundle: true,
    format: 'esm',
    target: ['es2020'],
    minify: !watch,
    sourcemap: watch ? 'inline' : false,
    logLevel: 'info',
};

const cssArgs = ['-i', 'assets/styles/app.css', '-o', 'public/build/app.css'];

if (watch) {
    await writeManifest({ 'build/app.css': '/build/app.css', 'build/app.js': '/build/app.js' });
    const ctx = await esbuild.context(jsOptions);
    await ctx.watch();
    await run(tailwindBin, [...cssArgs, '--watch=always']);
} else {
    await esbuild.build(jsOptions);
    await run(tailwindBin, [...cssArgs, '--minify']);
    const manifest = {};
    for (const file of ['app.css', 'app.js']) {
        manifest[`build/${file}`] = `/build/${await fingerprint(file)}`;
    }
    await writeManifest(manifest);
    const files = await readdir(outDir);
    console.log(`Built ${files.length} files into public/build`);
}
