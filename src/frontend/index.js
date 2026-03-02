import { render, Component } from "@wordpress/element";
import App from "./components/App";

import "./style/style.scss";

class ErrorBoundary extends Component {
  constructor(props) {
    super(props);
    this.state = { hasError: false, error: null };
  }

  static getDerivedStateFromError(error) {
    return { hasError: true, error };
  }

  componentDidCatch(error, errorInfo) {
    console.error("React ErrorBoundary capturó un error:", error, errorInfo);
  }

  render() {
    if (this.state.hasError) {
      return (
        <div
          style={{
            padding: "20px",
            background: "#f8d7da",
            color: "#721c24",
            border: "1px solid #f5c6cb",
            borderRadius: "4px",
          }}
        >
          <h2>Algo salió mal en el Dashboard de React.</h2>
          <details style={{ whiteSpace: "pre-wrap" }}>
            {this.state.error && this.state.error.toString()}
          </details>
        </div>
      );
    }
    return this.props.children;
  }
}

document.addEventListener("DOMContentLoaded", () => {
  const rootElement = document.getElementById("wp-api-creator-app");

  if (rootElement) {
    render(
      <ErrorBoundary>
        <App />
      </ErrorBoundary>,
      rootElement,
    );
  }
});
