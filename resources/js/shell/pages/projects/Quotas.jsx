// Die Kontingente eines Projekts. Die Seite selbst steht eine Ebene höher und
// bedient beide Ebenen — hier steht nur der Name, unter dem der Server sie
// anfordert. Er muss als Datei existieren: `ensure_pages_exist` lässt einen
// falsch geschriebenen Seitennamen sofort auffallen, und dieselbe Prüfung
// verlangt, dass es die Datei gibt.
export { default } from '../../components/QuotaPage.jsx';
