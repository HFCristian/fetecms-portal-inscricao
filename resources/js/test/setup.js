import '@testing-library/jest-dom';
import { configure } from '@testing-library/react';

// Espera das buscas assíncronas (`findBy*`, `waitFor`). O padrão do Testing
// Library é 1s, medido em tempo de relógio: numa máquina folgada sobra, mas o
// runner do CI tem 2 núcleos e roda 90 arquivos de teste em paralelo, e uma
// cadeia banal — clique → promise → setState → render — passa de 1s sob essa
// carga. O resultado eram falhas que não se reproduzem em lugar nenhum.
//
// Subir o teto **não mascara defeito**: quem falha continua falhando, só que
// por não acontecer, e não por demorar. O preço é o teste realmente quebrado
// levar 5s para desistir em vez de 1s.
configure({ asyncUtilTimeout: 5000 });

// jsdom não implementa scrollIntoView; componentes que rolam para o fim (ex.: chat)
// chamariam uma função inexistente nos testes. Stub no-op global.
if (!Element.prototype.scrollIntoView) {
    Element.prototype.scrollIntoView = () => {};
}
