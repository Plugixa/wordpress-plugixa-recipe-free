/*! Plugixa Recipe -- compiled bundle. Do not edit; regenerated on every build.
 * Human-readable source: assets/backend/app/src/ -- React admin application (TypeScript, MUI)
 * Rebuild: cd assets/backend/app && npm install && npm run build   (Node.js 20.19+ or 22.12+)
 * License: GPLv2 or later -- https://www.gnu.org/licenses/gpl-2.0.html
 */
/*! Plugixa Recipe -- compiled bundle -- source: assets/backend/app/src/ | rebuild: cd assets/backend/app && npm install && npm run build | GPLv2 or later */import{a as e,i as t,o as n,r,s as i}from"./queryClient-YBIL49Fb.js";var a=`/reviews`,o={getList:e=>t(a,e),getById:e=>t(`${a}/${e}`),update:(e,t)=>i(`${a}/${e}`,t),delete:e=>r(`${a}/${e}`),bulkDelete:e=>n(`${a}/bulk-delete`,{ids:e}),changeStatus:(t,n)=>e(`${a}/${t}/status`,{status:n}),bulkAction:(e,t,r)=>n(`${a}/bulk-action`,{action:e,ids:t,...r})};export{o as t};