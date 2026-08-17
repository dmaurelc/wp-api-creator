import { useState, useEffect, useCallback, useRef } from "@wordpress/element";
import { __ } from "@wordpress/i18n";
import apiFetch from "@wordpress/api-fetch";

/**
 * Opciones de usuario resueltas contra el servidor.
 *
 * La busqueda y el limite viven en `/admin/users`: el Combobox filtra sobre el array que
 * recibe, asi que traerse el listado completo agotaria la memoria de PHP en sitios con
 * decenas de miles de usuarios.
 */
export default function useUserOptions() {
  const [options, setOptions] = useState([]);
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState(null);
  const debounceRef = useRef(null);

  const fetchUsers = useCallback((search) => {
    setIsLoading(true);
    const query = search ? `?search=${encodeURIComponent(search)}` : "";

    apiFetch({ path: `/creator/v1/admin/users${query}` })
      .then((res) => {
        setError(null);
        setOptions(
          (res.users || []).map((user) => ({
            value: String(user.id),
            label: user.roles?.length
              ? `${user.name} (${user.roles.join(", ")})`
              : user.name,
          })),
        );
      })
      .catch((err) =>
        setError(
          err?.message ||
            __("No se pudo cargar la lista de usuarios.", "wp-api-creator"),
        ),
      )
      .finally(() => setIsLoading(false));
  }, []);

  // Debounce para no lanzar una petición por pulsación.
  const search = useCallback(
    (term) => {
      if (debounceRef.current) clearTimeout(debounceRef.current);
      debounceRef.current = setTimeout(() => fetchUsers(term), 250);
    },
    [fetchUsers],
  );

  useEffect(() => {
    fetchUsers("");
    return () => {
      if (debounceRef.current) clearTimeout(debounceRef.current);
    };
  }, [fetchUsers]);

  return { options, isLoading, error, search };
}
