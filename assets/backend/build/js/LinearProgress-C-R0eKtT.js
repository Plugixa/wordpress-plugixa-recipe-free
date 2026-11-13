import{r as e}from"./rolldown-runtime-hePW80VL.js";import{$ as t,A as n,D as r,Et as i,H as a,O as o,S as s,Tt as c,U as l,_t as u,c as d,et as f,j as p,k as m,o as h,tt as g,v as _,vt as v,y}from"./Button-ErrbGwSD.js";import{u as b}from"./api-DjTj7zpj.js";import{h as x}from"./Menu-CJm2jJDZ.js";import{i as S}from"./useControlled-CFPGGSzu.js";import{t as C}from"./SwitchBase-heLHCEQ3.js";var w=e(i(),1),T=c();function E(e){return f(`MuiCheckbox`,e)}var D=t(`MuiCheckbox`,[`root`,`checked`,`disabled`,`indeterminate`,`colorPrimary`,`colorSecondary`,`sizeSmall`,`sizeMedium`]),O=_((0,T.jsx)(`path`,{d:`M19 5v14H5V5h14m0-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z`}),`CheckBoxOutlineBlank`),k=_((0,T.jsx)(`path`,{d:`M19 3H5c-1.11 0-2 .9-2 2v14c0 1.1.89 2 2 2h14c1.11 0 2-.9 2-2V5c0-1.1-.89-2-2-2zm-9 14l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z`}),`CheckBox`),A=_((0,T.jsx)(`path`,{d:`M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-2 10H7v-2h10v2z`}),`IndeterminateCheckBox`),j=e=>{let{classes:t,indeterminate:n,color:i,size:a}=e,o={root:[`root`,n&&`indeterminate`,`color${r(i)}`,`size${r(a)}`]},s=l(o,E,t);return{...t,...s}},M=n(C,{shouldForwardProp:e=>p(e)||e===`classes`,name:`MuiCheckbox`,slot:`Root`,overridesResolver:(e,t)=>{let{ownerState:n}=e;return[t.root,n.indeterminate&&t.indeterminate,t[`size${r(n.size)}`],n.color!=="default"&&t[`color${r(n.color)}`]]}})(m(({theme:e})=>({color:(e.vars||e).palette.text.secondary,...e.focusVisible&&{[`&.${d.focusVisible} svg:first-of-type`]:{...a,borderRadius:(e.vars||e).shape.borderRadius,...e.focusVisible}},variants:[{props:{color:`default`,disableRipple:!1},style:{"&:hover":{backgroundColor:e.alpha((e.vars||e).palette.action.active,(e.vars||e).palette.action.hoverOpacity)}}},...Object.entries(e.palette).filter(h()).map(([t])=>({props:{color:t,disableRipple:!1},style:{"&:hover":{backgroundColor:e.alpha((e.vars||e).palette[t].main,(e.vars||e).palette.action.hoverOpacity)}}})),...Object.entries(e.palette).filter(h()).map(([t])=>({props:{color:t},style:{[`&.${D.checked}, &.${D.indeterminate}`]:{color:(e.vars||e).palette[t].main},[`&.${D.disabled}`]:{color:(e.vars||e).palette.action.disabled}}})),{props:{disableRipple:!1},style:{"&:hover":{"@media (hover: none)":{backgroundColor:`transparent`}}}}]}))),N=(0,T.jsx)(k,{}),P=(0,T.jsx)(O,{}),F=(0,T.jsx)(A,{}),I=w.forwardRef(function(e,t){let n=o({props:e,name:`MuiCheckbox`}),{checkedIcon:r=N,color:i=`primary`,icon:a=P,indeterminate:s=!1,indeterminateIcon:c=F,size:l=`medium`,disableRipple:u=!1,className:d,slots:f={},slotProps:p={},...m}=n,h=s?c:a,_=s?c:r,v={...n,disableRipple:u,color:i,indeterminate:s,size:l},y=j(v),S=p.input,[C,E]=b(`root`,{ref:t,elementType:M,className:g(y.root,d),shouldForwardComponentProp:!0,externalForwardedProps:{slots:f,slotProps:p,...m},ownerState:v,additionalProps:{type:`checkbox`,icon:w.cloneElement(h,{fontSize:h.props.fontSize??l}),checkedIcon:w.cloneElement(_,{fontSize:_.props.fontSize??l}),disableRipple:n.disableRipple,slots:f,slotProps:{input:x(typeof S==`function`?S(v):S,{"data-indeterminate":s,"aria-checked":s?`mixed`:void 0})}}});return(0,T.jsx)(C,{...E,classes:y})});function L(e){return f(`MuiLinearProgress`,e)}t(`MuiLinearProgress`,[`root`,`colorPrimary`,`colorSecondary`,`determinate`,`indeterminate`,`buffer`,`query`,`dashed`,`bar`,`bar1`,`bar2`]);var R=4,z={},B=v`
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
`,V=typeof B==`string`?null:u`
        animation: ${B} 2.1s cubic-bezier(0.65, 0.815, 0.735, 0.395) infinite;
      `,H=v`
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
`,U=typeof H==`string`?null:u`
        animation: ${H} 2.1s cubic-bezier(0.165, 0.84, 0.44, 1) 1.15s infinite;
      `,W=v`
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
`,G=typeof W==`string`?null:u`
        animation: ${W} 3s infinite linear;
      `,K=e=>{let{classes:t,variant:n,color:i}=e,a={root:[`root`,`color${r(i)}`,n],dashed:[`dashed`],bar1:[`bar`,`bar1`],bar2:[`bar`,`bar2`,n===`buffer`&&`color${r(i)}`]};return l(a,L,t)},q=(e,t)=>e.vars?e.vars.palette.LinearProgress[`${t}Bg`]:e.palette.mode===`light`?e.lighten(e.palette[t].main,.62):e.darken(e.palette[t].main,.5),J=n(`span`,{name:`MuiLinearProgress`,slot:`Root`,overridesResolver:(e,t)=>{let{ownerState:n}=e;return[t.root,t[`color${r(n.color)}`],t[n.variant]]}})(m(({theme:e})=>({position:`relative`,overflow:`hidden`,display:`block`,height:4,zIndex:0,"@media print":{colorAdjust:`exact`},variants:[...Object.entries(e.palette).filter(h()).map(([t])=>({props:{color:t},style:{backgroundColor:q(e,t)}})),{props:({ownerState:e})=>e.color===`inherit`&&e.variant!==`buffer`,style:{"&::before":{content:`""`,position:`absolute`,left:0,top:0,right:0,bottom:0,backgroundColor:`currentColor`,opacity:.3}}},{props:{variant:`buffer`},style:{backgroundColor:`transparent`}},{props:{variant:`query`},style:{transform:`rotate(180deg)`}}]}))),Y=n(`span`,{name:`MuiLinearProgress`,slot:`Dashed`})(m(({theme:e})=>({position:`absolute`,marginTop:0,height:`100%`,width:`100%`,backgroundSize:`10px 10px`,backgroundPosition:`0 -23px`,variants:[{props:{color:`inherit`},style:{opacity:.3,backgroundImage:`radial-gradient(currentColor 0%, currentColor 16%, transparent 42%)`}},...Object.entries(e.palette).filter(h()).map(([t])=>{let n=q(e,t);return{props:{color:t},style:{backgroundImage:`radial-gradient(${n} 0%, ${n} 16%, transparent 42%)`}}})]})),G||{animation:`${W} 3s infinite linear`},m(({theme:e})=>y(e,{animation:`none`})||z)),X=n(`span`,{name:`MuiLinearProgress`,slot:`Bar1`,overridesResolver:(e,t)=>[t.bar,t.bar1]})(m(({theme:e})=>{let t=y(e,{animation:`none`,left:`30%`,right:`auto`,width:`40%`});return{width:`100%`,position:`absolute`,left:0,bottom:0,top:0,...s(e,`transform`,{duration:`0.2s`,easing:`linear`}),transformOrigin:`left`,variants:[{props:{color:`inherit`},style:{backgroundColor:`currentColor`}},...Object.entries(e.palette).filter(h()).map(([t])=>({props:{color:t},style:{backgroundColor:(e.vars||e).palette[t].main}})),{props:{variant:`determinate`},style:{...s(e,`transform`,{duration:`.${R}s`,easing:`linear`})}},{props:{variant:`buffer`},style:{zIndex:1,...s(e,`transform`,{duration:`.${R}s`,easing:`linear`})}},{props:({ownerState:e})=>e.variant===`indeterminate`||e.variant===`query`,style:{width:`auto`}},{props:({ownerState:e})=>e.variant===`indeterminate`||e.variant===`query`,style:V||{animation:`${B} 2.1s cubic-bezier(0.65, 0.815, 0.735, 0.395) infinite`}},...t?[{props:({ownerState:e})=>e.variant===`indeterminate`||e.variant===`query`,style:t}]:[]]}})),Z=n(`span`,{name:`MuiLinearProgress`,slot:`Bar2`,overridesResolver:(e,t)=>[t.bar,t.bar2]})(m(({theme:e})=>{let t=y(e,{animation:`none`,display:`none`});return{width:`100%`,position:`absolute`,left:0,bottom:0,top:0,...s(e,`transform`,{duration:`0.2s`,easing:`linear`}),transformOrigin:`left`,variants:[...Object.entries(e.palette).filter(h()).map(([t])=>({props:{color:t},style:{"--LinearProgressBar2-barColor":(e.vars||e).palette[t].main}})),{props:({ownerState:e})=>e.variant!==`buffer`&&e.color!==`inherit`,style:{backgroundColor:`var(--LinearProgressBar2-barColor, currentColor)`}},{props:({ownerState:e})=>e.variant!==`buffer`&&e.color===`inherit`,style:{backgroundColor:`currentColor`}},{props:{color:`inherit`},style:{opacity:.3}},...Object.entries(e.palette).filter(h()).map(([t])=>({props:{color:t,variant:`buffer`},style:{backgroundColor:q(e,t),...s(e,`transform`,{duration:`.${R}s`,easing:`linear`})}})),{props:({ownerState:e})=>e.variant===`indeterminate`||e.variant===`query`,style:{width:`auto`}},{props:({ownerState:e})=>e.variant===`indeterminate`||e.variant===`query`,style:U||{animation:`${H} 2.1s cubic-bezier(0.165, 0.84, 0.44, 1) 1.15s infinite`}},...t?[{props:({ownerState:e})=>e.variant===`indeterminate`||e.variant===`query`,style:t}]:[]]}})),Q=w.forwardRef(function(e,t){let n=o({props:e,name:`MuiLinearProgress`}),{className:r,color:i=`primary`,max:a,min:s,value:c,valueBuffer:l,variant:u=`indeterminate`,...d}=n,f={...n,color:i,variant:u},p=s??0,m=a??100,h=K(f),_=S(),v={},y={bar1:{},bar2:{}};if((u===`determinate`||u===`buffer`)&&c!==void 0){let e=m-p,t=(c-p)/e*100-100;_&&(t=-t),y.bar1.transform=e>0?`translateX(${t}%)`:`translateX(-100%)`,v[`aria-valuenow`]=c,v[`aria-valuemin`]=p,v[`aria-valuemax`]=m}if(u===`buffer`&&l!==void 0){let e=m-p,t=(l-p)/e*100-100;_&&(t=-t),y.bar2.transform=e>0?`translateX(${t}%)`:`translateX(-100%)`}return(0,T.jsxs)(J,{className:g(h.root,r),ownerState:f,role:`progressbar`,...v,ref:t,...d,children:[u===`buffer`?(0,T.jsx)(Y,{className:h.dashed,ownerState:f}):null,(0,T.jsx)(X,{className:h.bar1,ownerState:f,style:y.bar1}),u===`determinate`?null:(0,T.jsx)(Z,{className:h.bar2,ownerState:f,style:y.bar2})]})});export{I as n,Q as t};