import{r as e}from"./rolldown-runtime-hePW80VL.js";import{C as t,G as n,H as r,K as i,L as a,N as o,Nt as s,P as c,Pt as l,U as u,W as d,_ as f,_t as p,at as m,dn as h,gt as g,h as _,ht as v,it as y,ln as b}from"./api-DK4NBYg4.js";import{h as x}from"./Menu-CWwhgWsl.js";import{s as S}from"./i18n-BxjYWi3R.js";import{t as C}from"./SwitchBase-DyTQDF1E.js";var w=e(h(),1),T=b();function E(e){return g(`MuiCheckbox`,e)}var D=v(`MuiCheckbox`,[`root`,`checked`,`disabled`,`indeterminate`,`colorPrimary`,`colorSecondary`,`sizeSmall`,`sizeMedium`]),O=o((0,T.jsx)(`path`,{d:`M19 5v14H5V5h14m0-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z`}),`CheckBoxOutlineBlank`),k=o((0,T.jsx)(`path`,{d:`M19 3H5c-1.11 0-2 .9-2 2v14c0 1.1.89 2 2 2h14c1.11 0 2-.9 2-2V5c0-1.1-.89-2-2-2zm-9 14l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z`}),`CheckBox`),A=o((0,T.jsx)(`path`,{d:`M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-2 10H7v-2h10v2z`}),`IndeterminateCheckBox`),j=e=>{let{classes:t,indeterminate:n,color:i,size:a}=e,o={root:[`root`,n&&`indeterminate`,`color${r(i)}`,`size${r(a)}`]},s=m(o,E,t);return{...t,...s}},M=n(C,{shouldForwardProp:e=>i(e)||e===`classes`,name:`MuiCheckbox`,slot:`Root`,overridesResolver:(e,t)=>{let{ownerState:n}=e;return[t.root,n.indeterminate&&t.indeterminate,t[`size${r(n.size)}`],n.color!=="default"&&t[`color${r(n.color)}`]]}})(d(({theme:e})=>({color:(e.vars||e).palette.text.secondary,...e.focusVisible&&{[`&.${f.focusVisible} svg:first-of-type`]:{...y,borderRadius:(e.vars||e).shape.borderRadius,...e.focusVisible}},variants:[{props:{color:`default`,disableRipple:!1},style:{"&:hover":{backgroundColor:e.alpha((e.vars||e).palette.action.active,(e.vars||e).palette.action.hoverOpacity)}}},...Object.entries(e.palette).filter(_()).map(([t])=>({props:{color:t,disableRipple:!1},style:{"&:hover":{backgroundColor:e.alpha((e.vars||e).palette[t].main,(e.vars||e).palette.action.hoverOpacity)}}})),...Object.entries(e.palette).filter(_()).map(([t])=>({props:{color:t},style:{[`&.${D.checked}, &.${D.indeterminate}`]:{color:(e.vars||e).palette[t].main},[`&.${D.disabled}`]:{color:(e.vars||e).palette.action.disabled}}})),{props:{disableRipple:!1},style:{"&:hover":{"@media (hover: none)":{backgroundColor:`transparent`}}}}]}))),N=(0,T.jsx)(k,{}),P=(0,T.jsx)(O,{}),F=(0,T.jsx)(A,{}),I=w.forwardRef(function(e,n){let r=u({props:e,name:`MuiCheckbox`}),{checkedIcon:i=N,color:a=`primary`,icon:o=P,indeterminate:s=!1,indeterminateIcon:c=F,size:l=`medium`,disableRipple:d=!1,className:f,slots:m={},slotProps:h={},...g}=r,_=s?c:o,v=s?c:i,y={...r,disableRipple:d,color:a,indeterminate:s,size:l},b=j(y),S=h.input,[C,E]=t(`root`,{ref:n,elementType:M,className:p(b.root,f),shouldForwardComponentProp:!0,externalForwardedProps:{slots:m,slotProps:h,...g},ownerState:y,additionalProps:{type:`checkbox`,icon:w.cloneElement(_,{fontSize:_.props.fontSize??l}),checkedIcon:w.cloneElement(v,{fontSize:v.props.fontSize??l}),disableRipple:r.disableRipple,slots:m,slotProps:{input:x(typeof S==`function`?S(y):S,{"data-indeterminate":s,"aria-checked":s?`mixed`:void 0})}}});return(0,T.jsx)(C,{...E,classes:b})});function L(e){return g(`MuiLinearProgress`,e)}v(`MuiLinearProgress`,[`root`,`colorPrimary`,`colorSecondary`,`determinate`,`indeterminate`,`buffer`,`query`,`dashed`,`bar`,`bar1`,`bar2`]);var R=4,z={},B=l`
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
`,V=typeof B==`string`?null:s`
        animation: ${B} 2.1s cubic-bezier(0.65, 0.815, 0.735, 0.395) infinite;
      `,H=l`
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
`,U=typeof H==`string`?null:s`
        animation: ${H} 2.1s cubic-bezier(0.165, 0.84, 0.44, 1) 1.15s infinite;
      `,W=l`
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
`,G=typeof W==`string`?null:s`
        animation: ${W} 3s infinite linear;
      `,K=e=>{let{classes:t,variant:n,color:i}=e,a={root:[`root`,`color${r(i)}`,n],dashed:[`dashed`],bar1:[`bar`,`bar1`],bar2:[`bar`,`bar2`,n===`buffer`&&`color${r(i)}`]};return m(a,L,t)},q=(e,t)=>e.vars?e.vars.palette.LinearProgress[`${t}Bg`]:e.palette.mode===`light`?e.lighten(e.palette[t].main,.62):e.darken(e.palette[t].main,.5),J=n(`span`,{name:`MuiLinearProgress`,slot:`Root`,overridesResolver:(e,t)=>{let{ownerState:n}=e;return[t.root,t[`color${r(n.color)}`],t[n.variant]]}})(d(({theme:e})=>({position:`relative`,overflow:`hidden`,display:`block`,height:4,zIndex:0,"@media print":{colorAdjust:`exact`},variants:[...Object.entries(e.palette).filter(_()).map(([t])=>({props:{color:t},style:{backgroundColor:q(e,t)}})),{props:({ownerState:e})=>e.color===`inherit`&&e.variant!==`buffer`,style:{"&::before":{content:`""`,position:`absolute`,left:0,top:0,right:0,bottom:0,backgroundColor:`currentColor`,opacity:.3}}},{props:{variant:`buffer`},style:{backgroundColor:`transparent`}},{props:{variant:`query`},style:{transform:`rotate(180deg)`}}]}))),Y=n(`span`,{name:`MuiLinearProgress`,slot:`Dashed`})(d(({theme:e})=>({position:`absolute`,marginTop:0,height:`100%`,width:`100%`,backgroundSize:`10px 10px`,backgroundPosition:`0 -23px`,variants:[{props:{color:`inherit`},style:{opacity:.3,backgroundImage:`radial-gradient(currentColor 0%, currentColor 16%, transparent 42%)`}},...Object.entries(e.palette).filter(_()).map(([t])=>{let n=q(e,t);return{props:{color:t},style:{backgroundImage:`radial-gradient(${n} 0%, ${n} 16%, transparent 42%)`}}})]})),G||{animation:`${W} 3s infinite linear`},d(({theme:e})=>c(e,{animation:`none`})||z)),X=n(`span`,{name:`MuiLinearProgress`,slot:`Bar1`,overridesResolver:(e,t)=>[t.bar,t.bar1]})(d(({theme:e})=>{let t=c(e,{animation:`none`,left:`30%`,right:`auto`,width:`40%`});return{width:`100%`,position:`absolute`,left:0,bottom:0,top:0,...a(e,`transform`,{duration:`0.2s`,easing:`linear`}),transformOrigin:`left`,variants:[{props:{color:`inherit`},style:{backgroundColor:`currentColor`}},...Object.entries(e.palette).filter(_()).map(([t])=>({props:{color:t},style:{backgroundColor:(e.vars||e).palette[t].main}})),{props:{variant:`determinate`},style:{...a(e,`transform`,{duration:`.${R}s`,easing:`linear`})}},{props:{variant:`buffer`},style:{zIndex:1,...a(e,`transform`,{duration:`.${R}s`,easing:`linear`})}},{props:({ownerState:e})=>e.variant===`indeterminate`||e.variant===`query`,style:{width:`auto`}},{props:({ownerState:e})=>e.variant===`indeterminate`||e.variant===`query`,style:V||{animation:`${B} 2.1s cubic-bezier(0.65, 0.815, 0.735, 0.395) infinite`}},...t?[{props:({ownerState:e})=>e.variant===`indeterminate`||e.variant===`query`,style:t}]:[]]}})),Z=n(`span`,{name:`MuiLinearProgress`,slot:`Bar2`,overridesResolver:(e,t)=>[t.bar,t.bar2]})(d(({theme:e})=>{let t=c(e,{animation:`none`,display:`none`});return{width:`100%`,position:`absolute`,left:0,bottom:0,top:0,...a(e,`transform`,{duration:`0.2s`,easing:`linear`}),transformOrigin:`left`,variants:[...Object.entries(e.palette).filter(_()).map(([t])=>({props:{color:t},style:{"--LinearProgressBar2-barColor":(e.vars||e).palette[t].main}})),{props:({ownerState:e})=>e.variant!==`buffer`&&e.color!==`inherit`,style:{backgroundColor:`var(--LinearProgressBar2-barColor, currentColor)`}},{props:({ownerState:e})=>e.variant!==`buffer`&&e.color===`inherit`,style:{backgroundColor:`currentColor`}},{props:{color:`inherit`},style:{opacity:.3}},...Object.entries(e.palette).filter(_()).map(([t])=>({props:{color:t,variant:`buffer`},style:{backgroundColor:q(e,t),...a(e,`transform`,{duration:`.${R}s`,easing:`linear`})}})),{props:({ownerState:e})=>e.variant===`indeterminate`||e.variant===`query`,style:{width:`auto`}},{props:({ownerState:e})=>e.variant===`indeterminate`||e.variant===`query`,style:U||{animation:`${H} 2.1s cubic-bezier(0.165, 0.84, 0.44, 1) 1.15s infinite`}},...t?[{props:({ownerState:e})=>e.variant===`indeterminate`||e.variant===`query`,style:t}]:[]]}})),Q=w.forwardRef(function(e,t){let n=u({props:e,name:`MuiLinearProgress`}),{className:r,color:i=`primary`,max:a,min:o,value:s,valueBuffer:c,variant:l=`indeterminate`,...d}=n,f={...n,color:i,variant:l},m=o??0,h=a??100,g=K(f),_=S(),v={},y={bar1:{},bar2:{}};if((l===`determinate`||l===`buffer`)&&s!==void 0){let e=h-m,t=(s-m)/e*100-100;_&&(t=-t),y.bar1.transform=e>0?`translateX(${t}%)`:`translateX(-100%)`,v[`aria-valuenow`]=s,v[`aria-valuemin`]=m,v[`aria-valuemax`]=h}if(l===`buffer`&&c!==void 0){let e=h-m,t=(c-m)/e*100-100;_&&(t=-t),y.bar2.transform=e>0?`translateX(${t}%)`:`translateX(-100%)`}return(0,T.jsxs)(J,{className:p(g.root,r),ownerState:f,role:`progressbar`,...v,ref:t,...d,children:[l===`buffer`?(0,T.jsx)(Y,{className:g.dashed,ownerState:f}):null,(0,T.jsx)(X,{className:g.bar1,ownerState:f,style:y.bar1}),l===`determinate`?null:(0,T.jsx)(Z,{className:g.bar2,ownerState:f,style:y.bar2})]})});export{I as n,Q as t};