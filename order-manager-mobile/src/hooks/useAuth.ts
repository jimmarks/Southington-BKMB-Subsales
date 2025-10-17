import { useState, useEffect } from 'react';
import { useDispatch, useSelector } from 'react-redux';
import { login, logout } from '../store/authSlice';
import { authService } from '../services/authService';

const useAuth = () => {
    const dispatch = useDispatch();
    const user = useSelector((state) => state.auth.user);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);

    const loginUser = async (credentials) => {
        setLoading(true);
        setError(null);
        try {
            const userData = await authService.login(credentials);
            dispatch(login(userData));
        } catch (err) {
            setError(err.message);
        } finally {
            setLoading(false);
        }
    };

    const logoutUser = () => {
        dispatch(logout());
    };

    useEffect(() => {
        // Optionally, you can add logic here to check for existing sessions
    }, []);

    return {
        user,
        loading,
        error,
        loginUser,
        logoutUser,
    };
};

export default useAuth;