// Build amd/src/*.js like Moodle's "grunt amd" (rollup + babel + terser, same options).
const path = require('path');
const rollup = require('rollup');
const {terser} = require('rollup-plugin-terser');
const babelCore = require('@babel/core');
const moodleroot = process.argv[2];
const plugindir = process.argv[3];
process.chdir(moodleroot);
const babel = () => ({
    name: 'babel',
    transform: (code, id) => {
        const t = babelCore.transform(code, {
            filename: id, sourceMaps: true, comments: false, compact: false,
            plugins: ['transform-es2015-modules-amd-lazy', 'system-import-transformer',
                path.resolve(moodleroot, '.grunt/babel-plugin-add-module-to-define.js')],
            presets: [['@babel/preset-env', {modules: false, useBuiltIns: false}]],
        });
        return {code: t.code, map: t.map};
    },
});
(async() => {
    const fs = require('fs');
    for (const file of fs.readdirSync(path.join(plugindir, 'amd/src')).filter((f) => f.endsWith('.js'))) {
        const input = path.join(plugindir, 'amd/src', file);
        const bundle = await rollup.rollup({input, treeshake: false, context: 'window', plugins: [babel(), terser({mangle: false})],
            external: (id) => id !== input});
        await bundle.write({file: path.join(plugindir, 'amd/build', file.replace(/\.js$/, '.min.js')), format: 'esm', sourcemap: true});
        console.log('built', file);
    }
})().catch((e) => { console.error(e); process.exit(1); });
