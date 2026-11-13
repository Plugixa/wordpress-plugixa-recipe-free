/*! Plugixa Recipe -- compiled bundle. Do not edit; regenerated on every build.
 * Human-readable source: assets/backend/app/src/ -- React admin application (TypeScript, MUI)
 * Rebuild: cd assets/backend/app && npm install && npm run build   (Node.js 20.19+ or 22.12+)
 * License: GPLv2 or later -- https://www.gnu.org/licenses/gpl-2.0.html
 */
/*! Plugixa Recipe -- compiled bundle -- source: assets/backend/app/src/ | rebuild: cd assets/backend/app && npm install && npm run build | GPLv2 or later */import{r as e}from"./rolldown-runtime-hePW80VL.js";import{G as t,H as n,L as r,Nt as i,P as a,Pt as o,U as s,W as c,_t as l,at as u,dn as d,gt as f,h as p,ht as m,ln as h}from"./api-DK4NBYg4.js";import{s as g}from"./i18n-BxjYWi3R.js";var _=e(d(),1);function v(e){return f(`MuiLinearProgress`,e)}m(`MuiLinearProgress`,[`root`,`colorPrimary`,`colorSecondary`,`determinate`,`indeterminate`,`buffer`,`query`,`dashed`,`bar`,`bar1`,`bar2`]);var y=h(),b=4,x={},S=o`
  0% {
    left: -35%;
    right: 100%;
  }

  60% {
    left: 100%;
    right: -90%;
  }

  100% {
    left: 100%;
    right: -90%;
  }
`,C=typeof S==`string`?null:i`
        animation: ${S} 2.1s cubic-bezier(0.65, 0.815, 0.735, 0.395) infinite;
      `,w=o`
  0% {
    left: -200%;
    right: 100%;
  }

  60% {
    left: 107%;
    right: -8%;
  }

  100% {
    left: 107%;
    right: -8%;
  }
`,T=typeof w==`string`?null:i`
        animation: ${w} 2.1s cubic-bezier(0.165, 0.84, 0.44, 1) 1.15s infinite;
      `,E=o`
  0% {
    opacity: 1;
    background-position: 0 -23px;
  }

  60% {
    opacity: 0;
    background-position: 0 -23px;
  }

  100% {
    opacity: 1;
    background-position: -200px -23px;
  }
`,D=typeof E==`string`?null:i`
        animation: ${E} 3s infinite linear;
      `,O=e=>{let{classes:t,variant:r,color:i}=e,a={root:[`root`,`color${n(i)}`,r],dashed:[`dashed`],bar1:[`bar`,`bar1`],bar2:[`bar`,`bar2`,r===`buffer`&&`color${n(i)}`]};return u(a,v,t)},k=(e,t)=>e.vars?e.vars.palette.LinearProgress[`${t}Bg`]:e.palette.mode===`light`?e.lighten(e.palette[t].main,.62):e.darken(e.palette[t].main,.5),A=t(`span`,{name:`MuiLinearProgress`,slot:`Root`,overridesResolver:(e,t)=>{let{ownerState:r}=e;return[t.root,t[`color${n(r.color)}`],t[r.variant]]}})(c(({theme:e})=>({position:`relative`,overflow:`hidden`,display:`block`,height:4,zIndex:0,"@media print":{colorAdjust:`exact`},variants:[...Object.entries(e.palette).filter(p()).map(([t])=>({props:{color:t},style:{backgroundColor:k(e,t)}})),{props:({ownerState:e})=>e.color===`inherit`&&e.variant!==`buffer`,style:{"&::before":{content:`""`,position:`absolute`,left:0,top:0,right:0,bottom:0,backgroundColor:`currentColor`,opacity:.3}}},{props:{variant:`buffer`},style:{backgroundColor:`transparent`}},{props:{variant:`query`},style:{transform:`rotate(180deg)`}}]}))),j=t(`span`,{name:`MuiLinearProgress`,slot:`Dashed`})(c(({theme:e})=>({position:`absolute`,marginTop:0,height:`100%`,width:`100%`,backgroundSize:`10px 10px`,backgroundPosition:`0 -23px`,variants:[{props:{color:`inherit`},style:{opacity:.3,backgroundImage:`radial-gradient(currentColor 0%, currentColor 16%, transparent 42%)`}},...Object.entries(e.palette).filter(p()).map(([t])=>{let n=k(e,t);return{props:{color:t},style:{backgroundImage:`radial-gradient(${n} 0%, ${n} 16%, transparent 42%)`}}})]})),D||{animation:`${E} 3s infinite linear`},c(({theme:e})=>a(e,{animation:`none`})||x)),M=t(`span`,{name:`MuiLinearProgress`,slot:`Bar1`,overridesResolver:(e,t)=>[t.bar,t.bar1]})(c(({theme:e})=>{let t=a(e,{animation:`none`,left:`30%`,right:`auto`,width:`40%`});return{width:`100%`,position:`absolute`,left:0,bottom:0,top:0,...r(e,`transform`,{duration:`0.2s`,easing:`linear`}),transformOrigin:`left`,variants:[{props:{color:`inherit`},style:{backgroundColor:`currentColor`}},...Object.entries(e.palette).filter(p()).map(([t])=>({props:{color:t},style:{backgroundColor:(e.vars||e).palette[t].main}})),{props:{variant:`determinate`},style:{...r(e,`transform`,{duration:`.${b}s`,easing:`linear`})}},{props:{variant:`buffer`},style:{zIndex:1,...r(e,`transform`,{duration:`.${b}s`,easing:`linear`})}},{props:({ownerState:e})=>e.variant===`indeterminate`||e.variant===`query`,style:{width:`auto`}},{props:({ownerState:e})=>e.variant===`indeterminate`||e.variant===`query`,style:C||{animation:`${S} 2.1s cubic-bezier(0.65, 0.815, 0.735, 0.395) infinite`}},...t?[{props:({ownerState:e})=>e.variant===`indeterminate`||e.variant===`query`,style:t}]:[]]}})),N=t(`span`,{name:`MuiLinearProgress`,slot:`Bar2`,overridesResolver:(e,t)=>[t.bar,t.bar2]})(c(({theme:e})=>{let t=a(e,{animation:`none`,display:`none`});return{width:`100%`,position:`absolute`,left:0,bottom:0,top:0,...r(e,`transform`,{duration:`0.2s`,easing:`linear`}),transformOrigin:`left`,variants:[...Object.entries(e.palette).filter(p()).map(([t])=>({props:{color:t},style:{"--LinearProgressBar2-barColor":(e.vars||e).palette[t].main}})),{props:({ownerState:e})=>e.variant!==`buffer`&&e.color!==`inherit`,style:{backgroundColor:`var(--LinearProgressBar2-barColor, currentColor)`}},{props:({ownerState:e})=>e.variant!==`buffer`&&e.color===`inherit`,style:{backgroundColor:`currentColor`}},{props:{color:`inherit`},style:{opacity:.3}},...Object.entries(e.palette).filter(p()).map(([t])=>({props:{color:t,variant:`buffer`},style:{backgroundColor:k(e,t),...r(e,`transform`,{duration:`.${b}s`,easing:`linear`})}})),{props:({ownerState:e})=>e.variant===`indeterminate`||e.variant===`query`,style:{width:`auto`}},{props:({ownerState:e})=>e.variant===`indeterminate`||e.variant===`query`,style:T||{animation:`${w} 2.1s cubic-bezier(0.165, 0.84, 0.44, 1) 1.15s infinite`}},...t?[{props:({ownerState:e})=>e.variant===`indeterminate`||e.variant===`query`,style:t}]:[]]}})),P=_.forwardRef(function(e,t){let n=s({props:e,name:`MuiLinearProgress`}),{className:r,color:i=`primary`,max:a,min:o,value:c,valueBuffer:u,variant:d=`indeterminate`,...f}=n,p={...n,color:i,variant:d},m=o??0,h=a??100,_=O(p),v=g(),b={},x={bar1:{},bar2:{}};if((d===`determinate`||d===`buffer`)&&c!==void 0){let e=h-m,t=(c-m)/e*100-100;v&&(t=-t),x.bar1.transform=e>0?`translateX(${t}%)`:`translateX(-100%)`,b[`aria-valuenow`]=c,b[`aria-valuemin`]=m,b[`aria-valuemax`]=h}if(d===`buffer`&&u!==void 0){let e=h-m,t=(u-m)/e*100-100;v&&(t=-t),x.bar2.transform=e>0?`translateX(${t}%)`:`translateX(-100%)`}return(0,y.jsxs)(A,{className:l(_.root,r),ownerState:p,role:`progressbar`,...b,ref:t,...f,children:[d===`buffer`?(0,y.jsx)(j,{className:_.dashed,ownerState:p}):null,(0,y.jsx)(M,{className:_.bar1,ownerState:p,style:x.bar1}),d===`determinate`?null:(0,y.jsx)(N,{className:_.bar2,ownerState:p,style:x.bar2})]})});export{P as t};