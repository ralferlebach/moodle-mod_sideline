// Jest configuration for the AMD panel (Moodle 4.5 to 5.2). Run from this directory with a
// toolchain providing jest, jest-environment-jsdom, babel-jest and @babel/preset-env (see
// docs/environment-setup.md). The React panel of Moodle 5.3 is tested by Moodle's own Jest setup.
module.exports = {
    rootDir: '../..',
    testEnvironment: 'jsdom',
    testMatch: ['<rootDir>/tests/js/**/*.test.js'],
    transform: {'^.+\\.js$': ['babel-jest', {presets: [['@babel/preset-env', {targets: {node: 'current'}}]]}]},
    moduleNameMapper: {'^core/ajax$': '<rootDir>/tests/js/mocks/ajax.js'},
};
