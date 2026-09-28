/**
 * Certains modules/extension de navigateur exposent par erreur les variables
 * CommonJS `exports` et `module` dans la page. Les distributions UMD de
 * DataTables les prennent alors pour un environnement Node.js et tentent
 * d'appeler `require()`. RISFM charge ces bibliotheques comme scripts navigateur.
 */
(function (root) {
    root.exports = undefined;
    root.module = undefined;
    root.require = undefined;
    root.define = undefined;
})(window);
