/*! Plugixa Recipe -- compiled bundle. Do not edit; regenerated on every build.
 * Human-readable source: assets/backend/app/src/ -- React admin application (TypeScript, MUI)
 * Rebuild: cd assets/backend/app && npm install && npm run build   (Node.js 20.19+ or 22.12+)
 * License: GPLv2 or later -- https://www.gnu.org/licenses/gpl-2.0.html
 */
/*! Plugixa Recipe -- compiled bundle -- source: assets/backend/app/src/ | rebuild: cd assets/backend/app && npm install && npm run build | GPLv2 or later */import{i as e,o as t,r as n,s as r}from"./queryClient-YBIL49Fb.js";var i=`/shopping-lists`,a={getList:t=>e(i,t),getById:t=>e(`${i}/${t}`),create:e=>t(i,e),update:(e,t)=>r(`${i}/${e}`,t),delete:e=>n(`${i}/${e}`),addItem:(e,n)=>t(`${i}/${e}/items`,n),updateItem:(e,t,n)=>r(`${i}/${e}/items/${t}`,n),removeItem:(e,t)=>n(`${i}/${e}/items/${t}`),fromRecipe:(e,n)=>t(`${i}/from-recipe`,{recipe_id:e,servings:n}),fromMealPlan:e=>t(`${i}/from-meal-plan`,{collection_id:e})};export{a as t};