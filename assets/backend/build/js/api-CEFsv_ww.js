/*! Plugixa Recipe -- compiled bundle. Do not edit; regenerated on every build.
 * Human-readable source: assets/backend/app/src/ -- React admin application (TypeScript, MUI)
 * Rebuild: cd assets/backend/app && npm install && npm run build   (Node.js 20.19+ or 22.12+)
 * License: GPLv2 or later -- https://www.gnu.org/licenses/gpl-2.0.html
 */
/*! Plugixa Recipe -- compiled bundle -- source: assets/backend/app/src/ | rebuild: cd assets/backend/app && npm install && npm run build | GPLv2 or later */import{a as e,i as t,n,t as r}from"./api-DK4NBYg4.js";var i=`/categories`,a={getList:e=>n(i,e),getById:e=>n(`${i}/${e}`),create:e=>t(i,e),update:(t,n)=>e(`${i}/${t}`,n),delete:e=>r(`${i}/${e}`),reorder:e=>t(`${i}/reorder`,{items:e}),bulkDelete:e=>r(`${i}/bulk`,{ids:e})};export{a as t};