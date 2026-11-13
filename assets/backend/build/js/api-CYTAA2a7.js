/*! Plugixa Recipe -- compiled bundle. Do not edit; regenerated on every build.
 * Human-readable source: assets/backend/app/src/ -- React admin application (TypeScript, MUI)
 * Rebuild: cd assets/backend/app && npm install && npm run build   (Node.js 20.19+ or 22.12+)
 * License: GPLv2 or later -- https://www.gnu.org/licenses/gpl-2.0.html
 */
/*! Plugixa Recipe -- compiled bundle -- source: assets/backend/app/src/ | rebuild: cd assets/backend/app && npm install && npm run build | GPLv2 or later */import{a as e,i as t,n,r,t as i}from"./api-DK4NBYg4.js";var a=`/reviews`,o={getList:e=>n(a,e),getById:e=>n(`${a}/${e}`),update:(t,n)=>e(`${a}/${t}`,n),delete:e=>i(`${a}/${e}`),bulkDelete:e=>i(`${a}/bulk`,{ids:e}),changeStatus:(e,t)=>r(`${a}/${e}/status`,{status:t}),bulkAction:(e,n,r)=>t(`${a}/bulk-action`,{action:e,ids:n,...r})};export{o as t};